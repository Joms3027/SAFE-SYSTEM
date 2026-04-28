// WPU Faculty System - Main JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Clean up old shown alerts from localStorage (older than 7 days)
    cleanupOldShownAlerts();
    
    // Prevent double form submissions
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            // If form is already submitting, prevent double submission
            if (this.classList.contains('is-submitting')) {
                e.preventDefault();
                return;
            }
            
            // Add submitting class and disable submit buttons
            this.classList.add('is-submitting');
            const submitButtons = this.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitButtons.forEach(button => {
                button.disabled = true;
                const originalText = button.innerHTML;
                button.setAttribute('data-original-text', originalText);
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            });
            
            // Clear old alerts before form submission
            const oldAlerts = document.querySelectorAll('.alert');
            oldAlerts.forEach(alert => alert.remove());
        });
    });

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Initialize sidebar automatically
    function initSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const toggles = document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]');
        const overlay = document.getElementById('sidebar-overlay');
        
        if (sidebar) {
            if (window.innerWidth >= 1025) {
                // Desktop: sidebar always visible
                sidebar.classList.remove('show');
                if (overlay) overlay.classList.remove('show');
                document.body.style.overflow = '';
                toggles.forEach(t => t.setAttribute('aria-expanded', 'true'));
            } else {
                // Mobile/Tablet: sidebar hidden by default
                sidebar.classList.remove('show');
                if (overlay) overlay.classList.remove('show');
                document.body.style.overflow = '';
                toggles.forEach(t => t.setAttribute('aria-expanded', 'false'));
            }
        }
    }
    
    // Call initSidebar on page load
    initSidebar();

    // Bootstrap auto-initialises dropdowns via data-bs-toggle="dropdown";
    // only create instances for user-dropdown toggles that need custom handling.
    document.querySelectorAll('.user-dropdown-wrapper .dropdown-toggle').forEach(function(el) {
        new bootstrap.Dropdown(el);
    });

    // Fix dropdown positioning and z-index (user-dropdown only; table dropdowns use CSS)
    document.querySelectorAll('.user-dropdown-wrapper .dropdown-menu').forEach(function(dropdown) {
        dropdown.style.zIndex = '1050';
    });

    // Function to sync backdrop with dropdown state
    function syncUserDropdownBackdrop(menu, isOpen) {
        if (!menu) return;
        
        const wrapper = menu.closest('.user-dropdown-wrapper');
        if (!wrapper) return;
        
        const isMobile = window.innerWidth <= 575.98;
        
        if (isOpen && isMobile) {
            wrapper.classList.add('show');
            document.body.style.overflow = 'hidden';
        } else {
            wrapper.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
    
    // Enhanced dropdown functionality with mobile backdrop support
    // Only apply custom handling to the user-dropdown in the header;
    // all other dropdowns rely on Bootstrap's built-in toggle.
    document.querySelectorAll('.user-dropdown-wrapper .dropdown-toggle').forEach(function(toggle) {
        const wrapper = toggle.closest('.user-dropdown-wrapper');
        const dropdownMenu = toggle.nextElementSibling;
        
        if (dropdownMenu && wrapper) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        const isOpen = dropdownMenu.classList.contains('show');
                        syncUserDropdownBackdrop(dropdownMenu, isOpen);
                        toggle.setAttribute('aria-expanded', isOpen);
                    }
                });
            });
            
            observer.observe(dropdownMenu, {
                attributes: true,
                attributeFilter: ['class']
            });
        }
        
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Close other user-dropdown menus
            document.querySelectorAll('.user-dropdown-wrapper .dropdown-menu.show').forEach(function(menu) {
                if (menu !== dropdownMenu) {
                    menu.classList.remove('show');
                    syncUserDropdownBackdrop(menu, false);
                }
            });
            
            if (dropdownMenu) {
                dropdownMenu.classList.toggle('show');
            }
        });
    });

    // Close user-dropdown when clicking outside (Bootstrap handles its own dropdowns)
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.user-dropdown-wrapper')) {
            document.querySelectorAll('.user-dropdown-wrapper .dropdown-menu.show').forEach(function(menu) {
                menu.classList.remove('show');
                syncUserDropdownBackdrop(menu, false);
            });
            document.querySelectorAll('.user-dropdown-wrapper .dropdown-toggle').forEach(function(toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            });
        }
    });
    
    // Close dropdowns on backdrop click (mobile) - handle clicks outside dropdown
    document.addEventListener('click', function(e) {
        const userDropdownWrapper = document.querySelector('.user-dropdown-wrapper.show');
        if (userDropdownWrapper && window.innerWidth <= 575.98) {
            const menu = userDropdownWrapper.querySelector('.user-dropdown-menu');
            const toggle = userDropdownWrapper.querySelector('.user-dropdown-toggle');
            
            // Check if click is outside the dropdown menu and toggle
            if (menu && menu.classList.contains('show') &&
                !menu.contains(e.target) && 
                !toggle.contains(e.target)) {
                menu.classList.remove('show');
                syncUserDropdownBackdrop(menu, false);
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            }
        }
    });
    
    // Handle window resize to close dropdowns
    window.addEventListener('resize', function() {
        if (window.innerWidth > 575.98) {
            // Remove backdrop on desktop
            document.querySelectorAll('.user-dropdown-menu.show').forEach(function(menu) {
                syncUserDropdownBackdrop(menu, false);
            });
        }
    });
    
    // Handle ESC key to close user-dropdown (Bootstrap handles its own)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.user-dropdown-wrapper .dropdown-menu.show').forEach(function(menu) {
                menu.classList.remove('show');
                syncUserDropdownBackdrop(menu, false);
            });
            document.querySelectorAll('.user-dropdown-wrapper .dropdown-toggle').forEach(function(toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            });
        }
    });

    // Enhanced Sidebar functionality (support multiple toggle buttons + overlay on small screens)
    const menuToggles = document.querySelectorAll('.menu-toggle');
    const sidebarEl = document.querySelector('.sidebar');
    const mainContentEl = document.querySelector('.main-content');

    // Utility to toggle overlay
    function toggleOverlay(show) {
        let overlay = document.getElementById('sidebar-overlay');
        if (show) {
            if (overlay) {
                overlay.classList.add('show');
            }
        } else {
            if (overlay) {
                overlay.classList.remove('show');
            }
        }
    }

    if (menuToggles && sidebarEl) {
        // Attach toggle handler to each menu toggle button (header, sidebar close, etc.)
        menuToggles.forEach(function(toggle) {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebarEl.classList.toggle('show');

                // update aria-expanded for all toggles
                const isOpen = sidebarEl.classList.contains('show');
                document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', isOpen ? 'true' : 'false'));

                // If now visible on small screens, show overlay
                if (window.innerWidth < 1025) {
                    toggleOverlay(isOpen);
                    // Prevent body scroll when sidebar is open on mobile/tablet
                    document.body.style.overflow = isOpen ? 'hidden' : '';
                }
            });
        });

        // Close sidebar when clicking overlay on mobile/tablet
        const overlay = document.getElementById('sidebar-overlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (window.innerWidth < 1025) {
                    sidebarEl.classList.remove('show');
                    toggleOverlay(false);
                    document.body.style.overflow = '';
                    document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
                }
            });
        }

        // Close sidebar when clicking outside on mobile/tablet
        document.addEventListener('click', function(e) {
            if (window.innerWidth < 1025) {
                if (!e.target.closest('.sidebar') && !e.target.closest('.menu-toggle') && sidebarEl.classList.contains('show')) {
                    sidebarEl.classList.remove('show');
                    toggleOverlay(false);
                    document.body.style.overflow = '';
                    document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
                }
            }
        });

        // Close sidebar button functionality (close button inside sidebar)
        const closeSidebarBtn = document.getElementById('closeSidebar');
        if (closeSidebarBtn) {
            closeSidebarBtn.addEventListener('click', function() {
                sidebarEl.classList.remove('show');
                toggleOverlay(false);
                document.body.style.overflow = '';
                document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
            });
        }

        // Reset sidebar state on window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth >= 1025) {
                    // Desktop: sidebar always visible, no overlay
                    sidebarEl.classList.remove('show');
                    toggleOverlay(false);
                    document.body.style.overflow = '';
                    document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'true'));
                } else {
                    // Mobile/Tablet: sidebar hidden by default
                    sidebarEl.classList.remove('show');
                    toggleOverlay(false);
                    document.body.style.overflow = '';
                    document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
                }
            }, 200);
        });
        
        // Close sidebar on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebarEl.classList.contains('show') && window.innerWidth < 1025) {
                sidebarEl.classList.remove('show');
                toggleOverlay(false);
                document.body.style.overflow = '';
                document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
            }
        });
    }

    // Initialize Toast Alert System
    initToastAlertSystem();
    
    // Show flash messages from session
    showFlashMessages();
    
    // Convert existing alerts to toast notifications (legacy support)
    convertAlertsToToasts();

    // File upload drag and drop
    const fileUploadAreas = document.querySelectorAll('.file-upload-area');
    fileUploadAreas.forEach(function(area) {
        const fileInput = area.querySelector('input[type="file"]');
        
        if (fileInput) {
            area.addEventListener('dragover', function(e) {
                e.preventDefault();
                area.classList.add('drag-over');
            });
            
            area.addEventListener('dragleave', function(e) {
                e.preventDefault();
                area.classList.remove('drag-over');
            });
            
            area.addEventListener('drop', function(e) {
                e.preventDefault();
                area.classList.remove('drag-over');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
            
            area.addEventListener('click', function() {
                fileInput.click();
            });
        }
    });

    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    // Table row click handlers
    document.querySelectorAll('.table tbody tr[data-href]').forEach(function(row) {
        row.addEventListener('click', function() {
            const href = this.getAttribute('data-href');
            if (href) {
                window.location.href = href;
            }
        });
    });

    // Search functionality
    const searchInputs = document.querySelectorAll('.search-input');
    searchInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const targetTable = document.querySelector(this.getAttribute('data-target'));
            
            if (targetTable) {
                const rows = targetTable.querySelectorAll('tbody tr');
                rows.forEach(function(row) {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        });
    });

    // Refresh dashboard data
    if (typeof refreshDashboard === 'function') {
        window.refreshDashboard = function() {
            location.reload();
        };
    }

    // Mobile-specific enhancements
    
    // Add data-label attributes for responsive tables
    function addTableLabels() {
        const tables = document.querySelectorAll('.table-responsive table');
        tables.forEach(function(table) {
            const headers = table.querySelectorAll('thead th');
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(function(row) {
                const cells = row.querySelectorAll('td');
                cells.forEach(function(cell, index) {
                    if (headers[index]) {
                        cell.setAttribute('data-label', headers[index].textContent.trim());
                    }
                });
            });
        });
    }
    
    // Add labels on page load and after dynamic content
    addTableLabels();
    
    // Re-add labels when tables are updated
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList') {
                addTableLabels();
            }
        });
    });
    
    // Observe table changes
    document.querySelectorAll('.table-responsive').forEach(function(container) {
        observer.observe(container, { childList: true, subtree: true });
    });
    
    // Prevent zoom on input focus for iOS
    if (/iPhone|iPad|iPod/.test(navigator.userAgent)) {
        const inputs = document.querySelectorAll('input[type="text"], input[type="email"], input[type="password"], input[type="number"], textarea, select');
        inputs.forEach(function(input) {
            if (!input.style.fontSize || parseInt(input.style.fontSize) < 16) {
                input.style.fontSize = '16px';
            }
        });
    }
    
    // Handle modal scrolling on mobile
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('show.bs.modal', function() {
            // Prevent body scroll when modal is open on mobile
            if (window.innerWidth < 768) {
                document.body.style.overflow = 'hidden';
            }
        });
        
        modal.addEventListener('hidden.bs.modal', function() {
            // Restore body scroll when modal is closed
            document.body.style.overflow = '';
        });
    });
    
    // Touch-friendly dropdown behavior for the user-dropdown only;
    // standard Bootstrap dropdowns already handle touch natively.
    if ('ontouchstart' in window) {
        document.querySelectorAll('.user-dropdown-wrapper .dropdown-toggle').forEach(function(toggle) {
            toggle.addEventListener('touchstart', function() {
                setTimeout(function() {
                    if (!toggle.disabled) {
                        toggle.click();
                    }
                }, 50);
            }, { passive: true });
        });
    }
    
    // Swipe to close sidebar on mobile
    let touchStartX = 0;
    let touchEndX = 0;
    
    if (sidebarEl) {
        sidebarEl.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].screenX;
        }, { passive: true });
        
        sidebarEl.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
        
        function handleSwipe() {
            const swipeDistance = touchStartX - touchEndX;
            // If swiped left more than 50px, close sidebar
            if (swipeDistance > 50 && window.innerWidth < 992) {
                sidebarEl.classList.add('collapsed');
                if (mainContentEl) mainContentEl.classList.add('expanded');
                toggleOverlay(false);
                document.querySelectorAll('.menu-toggle[aria-controls="sidebar"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
            }
        }
    }
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#' && href !== '#!') {
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
    
    // Better orientation change handling
    window.addEventListener('orientationchange', function() {
        setTimeout(function() {
            // Recalculate heights and positions
            if (window.innerWidth >= 992) {
                if (sidebarEl) sidebarEl.classList.remove('collapsed');
                if (mainContentEl) mainContentEl.classList.remove('expanded');
                toggleOverlay(false);
            } else {
                if (sidebarEl) sidebarEl.classList.add('collapsed');
                if (mainContentEl) mainContentEl.classList.add('expanded');
                toggleOverlay(false);
            }
        }, 100);
    });
});

// Logout Confirmation Function
// If an element is passed, read its `data-logout-url` attribute to redirect correctly.
function confirmLogout(el) {
    // Ensure Bootstrap is loaded
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap is not loaded. Logout cannot proceed.');
        // Fallback: direct logout
        const logoutUrl = (el && el.dataset && el.dataset.logoutUrl) ? el.dataset.logoutUrl : '../logout.php';
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = logoutUrl;
        }
        return;
    }
    
    // Create a custom confirmation modal
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'logoutModal';
    modal.setAttribute('tabindex', '-1');
    modal.setAttribute('aria-labelledby', 'logoutModalLabel');
    modal.setAttribute('aria-hidden', 'true');
    
    // Determine logout URL from element dataset if available
    let logoutUrl = '../logout.php';
    try {
        if (el && el.dataset && el.dataset.logoutUrl) {
            logoutUrl = el.dataset.logoutUrl;
        } else if (el && el.getAttribute && el.getAttribute('data-logout-url')) {
            logoutUrl = el.getAttribute('data-logout-url');
        }
    } catch (e) {
        console.error('Error getting logout URL:', e);
        // ignore and use default
    }

    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="logoutModalLabel">
                        <i class="fas fa-sign-out-alt text-danger me-2"></i>
                        Confirm Logout
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="fas fa-question-circle text-warning" style="font-size: 3rem;"></i>
                    </div>
                    <h6 class="mb-3">Are you sure you want to logout?</h6>
                    <p class="text-muted mb-0">You will be redirected to the login page and will need to sign in again to access the system.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmLogoutBtn">
                        <i class="fas fa-sign-out-alt me-1"></i>Yes, Logout
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.appendChild(modal);
    
    // Close sidebar if open (especially on mobile) to prevent z-index conflicts
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const wasSidebarOpen = sidebar && sidebar.classList.contains('show');
    
    if (wasSidebarOpen && sidebar) {
        sidebar.classList.remove('show');
        if (sidebarOverlay) {
            sidebarOverlay.classList.add('d-none');
        }
        if (window.closeSidebar && typeof window.closeSidebar === 'function') {
            window.closeSidebar();
        }
    }
    
    // Initialize and show modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
    
    // Remove modal from DOM when hidden
    modal.addEventListener('hidden.bs.modal', function() {
        document.body.removeChild(modal);
    });
    
    // Attach click handler to the confirm button to perform logout to the correct URL
    const confirmBtn = modal.querySelector('#confirmLogoutBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            performLogout(logoutUrl);
        });
    } else {
        console.error('Confirm logout button not found in modal');
    }
}

// Make confirmLogout available globally
window.confirmLogout = confirmLogout;

// Perform actual logout
function performLogout(url) {
    // Show loading state
    const logoutBtn = document.querySelector('#logoutModal .btn-danger');
    if (logoutBtn) {
        const originalText = logoutBtn.innerHTML;
        logoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Logging out...';
        logoutBtn.disabled = true;
    }

    // Redirect to logout page (use provided URL)
    // Use shorter timeout and ensure URL is valid
    const logoutUrl = url || '../logout.php';
    setTimeout(function() {
        try {
            window.location.href = logoutUrl;
        } catch (e) {
            console.error('Error redirecting to logout:', e);
            // Fallback: try direct redirect
            window.location = logoutUrl;
        }
    }, 500); // Reduced timeout for faster logout
}

// Make performLogout available globally
window.performLogout = performLogout;

// ===========================
// Toast Alert System
// ===========================

/**
 * Initialize the toast alert container
 */
function initToastAlertSystem() {
    // Check if container already exists
    if (document.getElementById('toast-container')) {
        return;
    }
    
    // Create toast container
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-notification-container';
    document.body.appendChild(container);
}

/**
 * Show a toast notification
 * @param {string} message - The message to display
 * @param {string} type - The type of alert (success, danger, warning, info)
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showToast(message, type = 'info', duration = 5000) {
    const container = document.getElementById('toast-container');
    if (!container) {
        initToastAlertSystem();
        return showToast(message, type, duration);
    }
    
    // Normalize type
    if (type === 'error') type = 'danger';
    
    // Check for duplicate messages (prevent showing same message multiple times)
    const existingToasts = container.querySelectorAll('.toast-notification');
    for (let existingToast of existingToasts) {
        const existingMessage = existingToast.querySelector('.toast-message');
        if (existingMessage && existingMessage.textContent.trim() === message.trim()) {
            // Same message already showing, don't create duplicate
            return existingToast;
        }
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.setAttribute('data-message', message);
    
    // Determine icon based on type
    let icon = 'fa-info-circle';
    switch(type) {
        case 'success':
            icon = 'fa-check-circle';
            break;
        case 'danger':
            icon = 'fa-exclamation-circle';
            break;
        case 'warning':
            icon = 'fa-exclamation-triangle';
            break;
        case 'info':
            icon = 'fa-info-circle';
            break;
    }
    
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div class="toast-content">
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="toast-progress"></div>
    `;
    
    // Add to container
    container.appendChild(toast);
    
    // Trigger animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    // Add close button functionality
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
        hideToast(toast);
    });
    
    // Auto-hide after duration
    if (duration > 0) {
        const progressBar = toast.querySelector('.toast-progress');
        progressBar.style.animationDuration = `${duration}ms`;
        
        setTimeout(() => {
            hideToast(toast);
        }, duration);
    }
    
    // Make toast clickable to dismiss
    toast.addEventListener('click', (e) => {
        if (!e.target.closest('.toast-close')) {
            hideToast(toast);
        }
    });
    
    return toast;
}

/**
 * Hide a toast notification
 * @param {HTMLElement} toast - The toast element to hide
 */
function hideToast(toast) {
    toast.classList.add('hide');
    toast.classList.remove('show');
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 300);
}

/**
 * Show flash messages from PHP session
 */
function showFlashMessages() {
    const flashContainer = document.getElementById('flash-messages');
    if (!flashContainer) return;
    
    try {
        const messagesData = flashContainer.getAttribute('data-messages');
        if (messagesData) {
            const messages = JSON.parse(messagesData);
            messages.forEach(msg => {
                // Check if this message has already been shown
                if (!hasAlertBeenShown(msg.id)) {
                    showToast(msg.message, msg.type);
                    // Mark this message as shown
                    markAlertAsShown(msg.id);
                }
            });
            // Remove the container after processing messages
            flashContainer.remove();
        }
    } catch (e) {
        console.error('Error parsing flash messages:', e);
    }
}

/**
 * Convert existing Bootstrap alerts to toast notifications (legacy support)
 */
function convertAlertsToToasts() {
    // Only convert alerts that are not inside modals and not already converted
    const alerts = document.querySelectorAll('.alert:not(.toast-converted):not(.modal .alert):not([data-no-toast="true"])');
    
    alerts.forEach((alert) => {
        // Mark as converted to prevent duplicate processing
        alert.classList.add('toast-converted');
        
        // Determine type from alert classes
        let type = 'info';
        if (alert.classList.contains('alert-success')) {
            type = 'success';
        } else if (alert.classList.contains('alert-danger')) {
            type = 'danger';
        } else if (alert.classList.contains('alert-warning')) {
            type = 'warning';
        } else if (alert.classList.contains('alert-info')) {
            type = 'info';
        }
        
        // Get message content (filter out button text)
        let message = '';
        const textNodes = [];
        
        // Get all text nodes, excluding button content
        const walker = document.createTreeWalker(
            alert,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    // Skip if parent is a button
                    if (node.parentElement && node.parentElement.tagName === 'BUTTON') {
                        return NodeFilter.FILTER_REJECT;
                    }
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode.textContent.trim());
        }
        
        message = textNodes.join(' ').trim();
        
        // Hide original alert
        alert.style.display = 'none';
        
        // Show as toast only if there's a message and it hasn't been shown before
        if (message && message.length > 0) {
            // Generate unique ID for legacy alerts based on message content and type
            const alertId = 'legacy_' + md5Simple(message + type);
            
            // Check if this alert has already been shown
            if (!hasAlertBeenShown(alertId)) {
                showToast(message, type);
                markAlertAsShown(alertId);
            }
        }
    });
}

/**
 * Simple MD5-like hash function for generating unique IDs
 * @param {string} str - String to hash
 * @returns {string} - Hash string
 */
function md5Simple(str) {
    let hash = 0;
    if (str.length === 0) return hash.toString();
    for (let i = 0; i < str.length; i++) {
        const char = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash; // Convert to 32bit integer
    }
    return Math.abs(hash).toString(36);
}

/**
 * Global function to show success message
 * @param {string} message 
 */
window.showSuccess = function(message) {
    showToast(message, 'success');
};

/**
 * Global function to show error message
 * @param {string} message 
 */
window.showError = function(message) {
    showToast(message, 'danger');
};

/**
 * Global function to show warning message
 * @param {string} message 
 */
window.showWarning = function(message) {
    showToast(message, 'warning');
};

/**
 * Global function to show info message
 * @param {string} message 
 */
window.showInfo = function(message) {
    showToast(message, 'info');
};

// ===========================
// Alert Tracking System
// ===========================

/**
 * Check if an alert has already been shown to the user
 * @param {string} alertId - Unique ID for the alert
 * @returns {boolean} - True if alert has been shown, false otherwise
 */
function hasAlertBeenShown(alertId) {
    if (!alertId) return false;
    
    try {
        const shownAlerts = JSON.parse(localStorage.getItem('shownAlerts') || '{}');
        return shownAlerts.hasOwnProperty(alertId);
    } catch (e) {
        console.error('Error checking shown alerts:', e);
        return false;
    }
}

/**
 * Mark an alert as shown
 * @param {string} alertId - Unique ID for the alert
 */
function markAlertAsShown(alertId) {
    if (!alertId) return;
    
    try {
        const shownAlerts = JSON.parse(localStorage.getItem('shownAlerts') || '{}');
        shownAlerts[alertId] = Date.now(); // Store timestamp when alert was shown
        localStorage.setItem('shownAlerts', JSON.stringify(shownAlerts));
    } catch (e) {
        console.error('Error marking alert as shown:', e);
    }
}

/**
 * Clean up old shown alerts from localStorage (older than 7 days)
 */
function cleanupOldShownAlerts() {
    try {
        const shownAlerts = JSON.parse(localStorage.getItem('shownAlerts') || '{}');
        const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000); // 7 days in milliseconds
        let cleaned = false;
        
        // Remove alerts older than 7 days
        for (const alertId in shownAlerts) {
            if (shownAlerts[alertId] < sevenDaysAgo) {
                delete shownAlerts[alertId];
                cleaned = true;
            }
        }
        
        if (cleaned) {
            localStorage.setItem('shownAlerts', JSON.stringify(shownAlerts));
        }
    } catch (e) {
        console.error('Error cleaning up shown alerts:', e);
    }
}

/**
 * Clear all shown alerts (useful for testing or user preference)
 */
window.clearShownAlerts = function() {
    try {
        localStorage.removeItem('shownAlerts');
        console.log('All shown alerts have been cleared');
    } catch (e) {
        console.error('Error clearing shown alerts:', e);
    }
};