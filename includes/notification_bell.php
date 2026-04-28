<!-- Notification Bell Component -->
<style>
/* Mobile-First Notification Bell Styles */
.notification-bell {
    position: relative;
    cursor: pointer;
    margin-right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    transition: all 0.3s ease;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
    z-index: 1031;
}

.notification-bell:hover {
    background: #e6f3ff;
}

.notification-bell:active {
    transform: scale(0.95);
    background: #dae9f9;
}

.notification-bell .bell-icon {
    font-size: 1.25rem;
    color: #003366;
    transition: color 0.3s ease;
}

.notification-bell:hover .bell-icon {
    color: #005599;
}

.notification-badge {
    position: absolute;
    top: 6px;
    right: 6px;
    background: #dc2626;
    color: white;
    border-radius: 10px;
    padding: 2px 5px;
    font-size: 0.65rem;
    font-weight: 700;
    min-width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

@keyframes ring {
    0%, 100% { transform: rotate(0); }
    10%, 30%, 50%, 70%, 90% { transform: rotate(-15deg); }
    20%, 40%, 60%, 80% { transform: rotate(15deg); }
}

@keyframes flash {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.7; transform: scale(1.3); }
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@keyframes pulse-ring {
    0% {
        transform: scale(0.8);
        opacity: 1;
    }
    50% {
        transform: scale(1.2);
        opacity: 0.5;
    }
    100% {
        transform: scale(0.8);
        opacity: 1;
    }
}

.notification-bell .bell-icon.ring {
    animation: ring 0.8s ease-in-out;
    color: var(--primary-blue);
}

.notification-badge.flash {
    animation: flash 0.5s ease-in-out;
}

/* Improved Loading Indicator */
.notification-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 1.5rem;
    min-height: 200px;
}

.notification-loading-spinner {
    position: relative;
    width: 48px;
    height: 48px;
    margin-bottom: 1rem;
}

.notification-loading-spinner::before,
.notification-loading-spinner::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    border: 3px solid transparent;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
}

.notification-loading-spinner::before {
    border-top-color: #003366;
    border-right-color: #003366;
    animation: spin 1s linear infinite;
}

.notification-loading-spinner::after {
    border-bottom-color: #e6f3ff;
    border-left-color: #e6f3ff;
    animation: spin 0.8s linear infinite reverse;
    opacity: 0.6;
}

.notification-loading-text {
    color: #64748b;
    font-size: 0.875rem;
    font-weight: 500;
    margin-top: 0.5rem;
    animation: pulse-ring 2s ease-in-out infinite;
}

.notification-loading-dots {
    display: inline-flex;
    gap: 0.25rem;
    margin-top: 0.5rem;
}

.notification-loading-dots span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #003366;
    animation: pulse-ring 1.4s ease-in-out infinite;
}

.notification-loading-dots span:nth-child(1) {
    animation-delay: 0s;
}

.notification-loading-dots span:nth-child(2) {
    animation-delay: 0.2s;
}

.notification-loading-dots span:nth-child(3) {
    animation-delay: 0.4s;
}

/* Responsive loading indicator */
@media (max-width: 767px) {
    .notification-loading {
        padding: 2rem 1rem;
        min-height: 150px;
    }
    
    .notification-loading-spinner {
        width: 40px;
        height: 40px;
    }
    
    .notification-loading-text {
        font-size: 0.8rem;
    }
}

.notification-toast {
    position: fixed;
    top: 70px;
    right: 12px;
    left: 12px;
    background: white;
    padding: 0.875rem 1rem;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    border-left: 4px solid #003366;
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    opacity: 0;
    transform: translateY(-20px);
    transition: all 0.3s ease;
    max-width: calc(100% - 24px);
}

.notification-toast.show {
    opacity: 1;
    transform: translateY(0);
}

.notification-toast i {
    color: #003366;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.notification-toast span {
    font-weight: 500;
    color: #1e293b;
    font-size: 0.875rem;
    flex: 1;
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.notification-dropdown {
    position: fixed;
    top: 60px;
    right: 8px;
    left: 8px;
    max-height: calc(100vh - 70px);
    background: white;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
    display: none;
    z-index: 1050;
    overflow: hidden;
}

.notification-dropdown.show {
    display: block;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification-header {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #e6f3ff 0%, #f0f9ff 100%);
    position: sticky;
    top: 0;
    z-index: 1;
}

.notification-header h6 {
    margin: 0;
    color: #003366;
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.notification-header h6 i {
    font-size: 1rem;
}

.mark-all-read {
    font-size: 0.8rem;
    color: #003366;
    cursor: pointer;
    text-decoration: none;
    padding: 0.375rem 0.75rem;
    border-radius: 8px;
    transition: all 0.2s ease;
    white-space: nowrap;
    font-weight: 500;
}

.mark-all-read:hover {
    background: white;
    color: #005599;
}

.notification-list {
    max-height: calc(100vh - 180px);
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
}

.notification-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.2s ease;
    position: relative;
    touch-action: manipulation;
    -webkit-tap-highlight-color: rgba(0, 51, 102, 0.05);
    user-select: none;
}

.notification-item:active {
    background: #e6f3ff;
}

.notification-item:hover {
    background: #f8fafc;
}

.notification-item.unread {
    background: #f0f9ff;
}

.notification-item.unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 60%;
    background: #003366;
    border-radius: 0 4px 4px 0;
}

.notification-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    margin-right: 0.75rem;
    flex-shrink: 0;
}

.notification-icon.submission_status { background: #e0f2fe; color: #0284c7; }
.notification-icon.submission { background: #e0f2fe; color: #0284c7; }
.notification-icon.pds_status { background: #e0e7ff; color: #6366f1; }
.notification-icon.pardon_request { background: #fef3c7; color: #d97706; }
.notification-icon.new_requirement { background: #fef3c7; color: #d97706; }
.notification-icon.deadline_reminder { background: #fee2e2; color: #dc2626; }
.notification-icon.announcement { background: #ddd6fe; color: #7c3aed; }
.notification-icon.system { background: #e5e7eb; color: #6b7280; }

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-title {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.25rem;
    font-size: 0.875rem;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.notification-message {
    color: #64748b;
    font-size: 0.8rem;
    margin-bottom: 0.25rem;
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.notification-time {
    font-size: 0.7rem;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.notification-time i {
    font-size: 0.65rem;
}

.notification-footer {
    padding: 0.875rem 1rem;
    border-top: 1px solid #e2e8f0;
    text-align: center;
    background: #fafbfc;
    position: sticky;
    bottom: 0;
}

.notification-footer a {
    color: #003366;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.notification-footer a:hover {
    background: white;
    color: #005599;
}

.no-notifications {
    padding: 2.5rem 1rem;
    text-align: center;
}

.no-notifications i {
    font-size: 2.5rem;
    color: #cbd5e1;
    margin-bottom: 0.75rem;
}

.no-notifications p {
    color: #94a3b8;
    margin: 0;
    font-size: 0.875rem;
}

.notification-priority-high {
    border-left: 3px solid #dc2626;
}

/* Tablet Optimizations (768px - 991px) */
@media (min-width: 768px) and (max-width: 991px) {
    .notification-dropdown {
        right: 12px;
        left: auto;
        width: 400px;
        max-height: calc(100vh - 80px);
    }
    
    .notification-toast {
        right: 16px;
        left: auto;
        max-width: 380px;
    }
    
    .notification-list {
        max-height: calc(100vh - 200px);
    }
}

/* Notification Dropdown Backdrop for Mobile */
.notification-backdrop {
    position: fixed;
    top: 56px;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    z-index: 1045;
    display: none;
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}

.notification-backdrop.show {
    display: block;
}

/* Desktop Optimizations (992px+) */
@media (min-width: 992px) {
    .notification-bell {
        margin-right: 0.5rem;
    }
    
    .notification-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        left: auto;
        margin-top: 0.5rem;
        width: 420px;
        max-height: 600px;
        border-radius: 12px;
    }
    
    .notification-backdrop {
        display: none !important;
    }
    
    .notification-header {
        padding: 1rem 1.25rem;
    }
    
    .notification-header h6 {
        font-size: 1rem;
    }
    
    .notification-item {
        padding: 1rem 1.25rem;
    }
    
    .notification-icon {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
        margin-right: 1rem;
    }
    
    .notification-title {
        font-size: 0.925rem;
    }
    
    .notification-message {
        font-size: 0.85rem;
    }
    
    .notification-list {
        max-height: 480px;
    }
    
    .notification-toast {
        top: 80px;
        right: 20px;
        left: auto;
        max-width: 420px;
        padding: 1rem 1.25rem;
        transform: translateX(450px);
    }
    
    .notification-toast.show {
        transform: translateX(0);
    }
    
    .notification-toast i {
        font-size: 1.2rem;
    }
    
    .notification-toast span {
        font-size: 0.925rem;
    }
}
</style>

<?php
// Get notification manager
require_once __DIR__ . '/notifications.php';
$notificationManager = getNotificationManager();
$unreadCount = 0;

if (isLoggedIn()) {
    $unreadCount = $notificationManager->getUnreadCount($_SESSION['user_id']);
}
?>

<div class="notification-bell" id="notificationBell">
    <i class="fas fa-bell bell-icon"></i>
    <?php if ($unreadCount > 0): ?>
        <span class="notification-badge" id="notificationBadge"><?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
    <?php endif; ?>
</div>

<!-- Notification Backdrop for Mobile -->
<div class="notification-backdrop d-lg-none" id="notificationBackdrop"></div>

<!-- Notification Dropdown -->
<div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <h6><i class="fas fa-bell me-2"></i>Notifications</h6>
            <a href="#" class="mark-all-read" id="markAllRead">Mark all as read</a>
        </div>
        
        <div class="notification-list" id="notificationList">
            <div class="notification-loading">
                <div class="notification-loading-spinner" role="status" aria-label="Loading notifications">
                    <span class="visually-hidden">Loading notifications...</span>
                </div>
                <div class="notification-loading-text">Loading notifications</div>
                <div class="notification-loading-dots">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </div>
        
        <div class="notification-footer">
            <?php
            // Mobile-friendly URL construction for notifications page
            $userType = $_SESSION['user_type'] ?? '';
            // Use a simple relative path - will be corrected by JavaScript if needed
            $notificationsPath = ($userType === 'admin') ? 'notifications.php' : 'notifications.php';
            ?>
            <a href="<?php echo htmlspecialchars($notificationsPath); ?>" 
               id="notificationsPageLink"
               data-user-type="<?php echo htmlspecialchars($userType); ?>">
                View all notifications
            </a>
        </div>
    </div>
</div>
</div>

<script>
// Notification Bell JavaScript
(function() {
    const bell = document.getElementById('notificationBell');
    const dropdown = document.getElementById('notificationDropdown');
    const backdrop = document.getElementById('notificationBackdrop');
    const badge = document.getElementById('notificationBadge');
    const list = document.getElementById('notificationList');
    const markAllReadBtn = document.getElementById('markAllRead');
    
    if (!bell || !dropdown || !list) {
        console.error('Notification bell elements not found in DOM');
        return;
    }
    
    let isOpen = false;
    
    // Audio context for notification sounds
    let notificationAudioContext = null;
    let notificationAudioInitialized = false;
    let notificationAudioInitializing = false;
    
    // Initialize audio on first user interaction (required by browsers)
    function enableNotificationAudioOnInteraction() {
        const events = ['click', 'touchstart', 'keydown', 'mousedown', 'touchend'];
        const initAudio = function(e) {
            // Only create AudioContext in response to trusted user gesture (prevents browser warning)
            if (e && !e.isTrusted) return;
            // Prevent multiple simultaneous initialization attempts
            if (notificationAudioInitialized || notificationAudioInitializing) {
                return;
            }
            
            notificationAudioInitializing = true;
            
            try {
                // Create AudioContext for Web Audio API beeps (must be done in user gesture handler)
                if (!notificationAudioContext) {
                    notificationAudioContext = new (window.AudioContext || window.webkitAudioContext)();
                }
                
                // Always try to resume the AudioContext immediately after creation
                // This ensures it's activated within the user gesture handler
                if (notificationAudioContext.state === 'suspended') {
                    // Resume must be called synchronously within the user gesture
                    const resumePromise = notificationAudioContext.resume();
                    if (resumePromise !== undefined) {
                        resumePromise.then(() => {
                            notificationAudioInitialized = true;
                            notificationAudioInitializing = false;
                            console.log('✅ Notification audio initialized successfully');
                            
                            // Remove event listeners after successful init
                            events.forEach(event => {
                                document.removeEventListener(event, initAudio);
                            });
                        }).catch(e => {
                            notificationAudioInitializing = false;
                            console.log('⏳ Notification audio init pending:', e);
                        });
                    } else {
                        // If resume() doesn't return a promise, mark as initialized
                        notificationAudioInitialized = true;
                        notificationAudioInitializing = false;
                        events.forEach(event => {
                            document.removeEventListener(event, initAudio);
                        });
                    }
                } else if (notificationAudioContext.state === 'running') {
                    notificationAudioInitialized = true;
                    notificationAudioInitializing = false;
                    console.log('✅ Notification audio initialized successfully');
                    
                    // Remove event listeners after successful init
                    events.forEach(event => {
                        document.removeEventListener(event, initAudio);
                    });
                } else {
                    // If state is 'closed' or unknown, try to resume anyway
                    notificationAudioContext.resume().then(() => {
                        notificationAudioInitialized = true;
                        notificationAudioInitializing = false;
                        events.forEach(event => {
                            document.removeEventListener(event, initAudio);
                        });
                    }).catch(() => {
                        notificationAudioInitializing = false;
                    });
                }
            } catch (error) {
                notificationAudioInitializing = false;
                // Silently handle errors - audio may not be available
                console.log('❌ Notification audio initialization error:', error);
            }
        };
        
        // Add listeners for all events
        events.forEach(event => {
            document.addEventListener(event, initAudio, { once: false, passive: true });
        });
    }
    
    // Initialize audio on page load (but don't create AudioContext until user interaction)
    // This prevents the "AudioContext was not allowed to start" error
    enableNotificationAudioOnInteraction();
    
    // Ensure AudioContext is only created after user interaction
    // Don't try to create it on page load
    
    // Toggle dropdown with touch support
    function toggleDropdown(e) {
        e.preventDefault();
        e.stopPropagation();
        
        isOpen = !isOpen;
        
        if (isOpen) {
            dropdown.classList.add('show');
            if (backdrop) backdrop.classList.add('show');
            loadNotifications();
            // Prevent body scroll on mobile when dropdown is open
            if (window.innerWidth < 992) {
                document.body.style.overflow = 'hidden';
            }
        } else {
            dropdown.classList.remove('show');
            if (backdrop) backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
    
    // Close dropdown function
    function closeDropdownFunc() {
        if (isOpen) {
            dropdown.classList.remove('show');
            if (backdrop) backdrop.classList.remove('show');
            isOpen = false;
            document.body.style.overflow = '';
        }
    }
    
    // Add both click and touch listeners
    bell.addEventListener('click', toggleDropdown);
    bell.addEventListener('touchend', function(e) {
        // Only handle if not already handled by click
        if (e.cancelable) {
            e.preventDefault();
            toggleDropdown(e);
        }
    });
    
    // Close when clicking backdrop
    if (backdrop) {
        backdrop.addEventListener('click', closeDropdownFunc);
        backdrop.addEventListener('touchstart', closeDropdownFunc, { passive: true });
    }
    
    // Close when clicking outside
    function closeDropdown(e) {
        if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
            closeDropdownFunc();
        }
    }
    
    document.addEventListener('click', closeDropdown);
    document.addEventListener('touchstart', closeDropdown, { passive: true });
    
    // Get base URL - mobile-friendly method with multiple fallbacks
    function getApiBaseUrl() {
        // Try to use the PHP-generated base URL first
        const phpBaseUrl = '<?php echo getBaseUrl(); ?>';
        if (phpBaseUrl && phpBaseUrl !== '' && phpBaseUrl !== 'undefined' && phpBaseUrl.indexOf('undefined') === -1) {
            return phpBaseUrl;
        }
        
        // Fallback 1: construct from current page location
        const currentUrl = window.location.origin;
        const pathname = window.location.pathname;
        
        // Extract base path (remove /faculty, /admin, /HR_EVENT, etc.)
        let basePath = pathname;
        if (basePath.includes('/HR_EVENT/')) {
            basePath = basePath.substring(0, basePath.indexOf('/HR_EVENT'));
        } else if (basePath.includes('/faculty/')) {
            basePath = basePath.substring(0, basePath.indexOf('/faculty'));
        } else if (basePath.includes('/admin/')) {
            basePath = basePath.substring(0, basePath.indexOf('/admin'));
        } else if (basePath.includes('/includes/')) {
            basePath = basePath.substring(0, basePath.indexOf('/includes'));
        }
        
        // If basePath is empty or just '/', use root
        if (!basePath || basePath === '/') {
            return currentUrl;
        }
        
        return currentUrl + basePath;
    }
    
    // Get API URL - uses relative path as ultimate fallback for mobile
    function getApiUrl(endpoint) {
        const baseUrl = getApiBaseUrl();
        const fullUrl = baseUrl + endpoint;
        
        // Calculate relative path based on current page location
        const pathname = window.location.pathname;
        let relativePath = endpoint;
        
        // Extract the endpoint part (everything after /includes/)
        const endpointPart = endpoint.includes('/includes/') 
            ? endpoint.substring(endpoint.indexOf('/includes/') + '/includes/'.length)
            : endpoint.replace(/^\//, '');
        
        // If we're in /faculty/ or /admin/, we need to go up one level; HR_EVENT/admin needs two levels
        if (pathname.includes('/HR_EVENT/')) {
            relativePath = '../../includes/' + endpointPart;
        } else if (pathname.includes('/faculty/') || pathname.includes('/admin/')) {
            relativePath = '../includes/' + endpointPart;
        } else {
            // Already at root level, use absolute path
            relativePath = endpoint.startsWith('/') ? endpoint : '/' + endpoint;
        }
        
        // For mobile devices, also try relative path if absolute fails
        // Store both for fallback
        return {
            absolute: fullUrl,
            relative: relativePath
        };
    }
    
    // Show loading state
    function showLoadingState() {
        list.innerHTML = `
            <div class="notification-loading">
                <div class="notification-loading-spinner" role="status" aria-label="Loading notifications">
                    <span class="visually-hidden">Loading notifications...</span>
                </div>
                <div class="notification-loading-text">Loading notifications</div>
                <div class="notification-loading-dots">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        `;
    }
    
    // Load notifications with mobile-friendly fallback
    function loadNotifications() {
        console.log('Loading notifications...');
        showLoadingState();
        
        // Build API URL - use relative path first
        const pathname = window.location.pathname;
        let apiUrl = '../includes/notifications_api.php?action=get_unread';
        
        // Adjust path based on current location
        if (pathname.includes('/HR_EVENT/')) {
            apiUrl = '../../includes/notifications_api.php?action=get_unread';
        } else if (pathname.includes('/faculty/') || pathname.includes('/admin/')) {
            apiUrl = '../includes/notifications_api.php?action=get_unread';
        } else if (pathname.includes('/includes/')) {
            apiUrl = 'notifications_api.php?action=get_unread';
        } else {
            // Try absolute path as fallback
            const basePath = getApiBaseUrl();
            apiUrl = basePath + '/includes/notifications_api.php?action=get_unread';
        }
        
        console.log('API URL:', apiUrl);
        console.log('Current location:', window.location.href);
        
        // Helper function to check if error is a blocked request
        function isBlockedRequest(error) {
            if (!error || !error.message) return false;
            const msg = error.message.toLowerCase();
            return msg.includes('failed to fetch') || 
                   msg.includes('blocked') || 
                   msg.includes('networkerror') ||
                   (error.name && error.name === 'TypeError');
        }
        
        // Try to fetch notifications
        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.text();
        })
        .then(text => {
            console.log('Raw response (first 500 chars):', text.substring(0, 500));
            try {
                const data = JSON.parse(text);
                console.log('Parsed data:', data);
                return data;
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response');
            }
        })
        .then(data => {
            console.log('=== Processing notification data ===');
            console.log('Full API response:', data);
            console.log('data.success =', data.success);
            console.log('data.notifications =', data.notifications);
            console.log('data.count =', data.count);
            
            if (data && data.success) {
                const notifications = data.notifications || [];
                const count = data.count || 0;
                
                console.log('API success! Number of notifications:', notifications.length);
                
                if (!Array.isArray(notifications)) {
                    console.error('ERROR: data.notifications is not an array! Type:', typeof notifications);
                    list.innerHTML = '<div class="no-notifications"><p>Error: Invalid notification data format</p></div>';
                    return;
                }
                
                displayNotifications(notifications);
                updateBadge(count);
            } else {
                console.error('API returned success=false:', data);
                const errorMsg = data && data.message ? data.message : 'Unknown error';
                list.innerHTML = '<div class="no-notifications"><p>Error: ' + errorMsg + '</p></div>';
            }
        })
        .catch(error => {
            // Only log non-blocked errors
            if (!isBlockedRequest(error)) {
                console.error('Error loading notifications:', error);
                list.innerHTML = '<div class="no-notifications"><p>Unable to load notifications. Please check your connection.</p></div>';
            } else {
                // For blocked requests, just show empty state silently
                list.innerHTML = '<div class="no-notifications"><p>No new notifications</p></div>';
            }
        });
    }
    
    // Display notifications
    function displayNotifications(notifications) {
        console.log('=== displayNotifications called ===');
        console.log('Notifications parameter type:', typeof notifications);
        console.log('Notifications is Array?', Array.isArray(notifications));
        console.log('Notifications count:', notifications ? notifications.length : 'null/undefined');
        console.log('Full notifications object:', notifications);
        
        if (!notifications || notifications.length === 0) {
            console.warn('No notifications to display - showing empty state');
            list.innerHTML = `
                <div class="no-notifications">
                    <i class="fas fa-bell-slash"></i>
                    <p>No new notifications</p>
                </div>
            `;
            return;
        }
        
        console.log('Rendering', notifications.length, 'notifications');
        console.log('First notification sample:', notifications[0]);
        
        const htmlContent = notifications.map(notif => {
            console.log('Rendering notification ID:', notif.id, 'Title:', notif.title);
            // Use data attributes to avoid JS syntax errors from special chars in URLs (quotes, etc.)
            const safeLink = (notif.link_url || '#').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return `
            <div class="notification-item ${notif.is_read == 0 ? 'unread' : ''} ${notif.priority === 'high' ? 'notification-priority-high' : ''}" 
                 data-id="${notif.id}" 
                 data-link="${safeLink}"
                 onclick="handleNotificationClick(${notif.id}, this, event)"
                 ontouchend="if(event.cancelable) { event.preventDefault(); handleNotificationClick(${notif.id}, this, event); }">
                <div class="d-flex align-items-start">
                    <div class="notification-icon ${notif.type}">
                        ${getNotificationIcon(notif.type)}
                    </div>
                    <div class="notification-content">
                        <div class="notification-title">${escapeHtml(notif.title)}</div>
                        <div class="notification-message">${escapeHtml(notif.message)}</div>
                        <div class="notification-time">
                            <i class="far fa-clock me-1"></i>${formatTime(notif.created_at)}
                        </div>
                    </div>
                </div>
            </div>
        `;
        }).join('');
        
        console.log('Generated HTML length:', htmlContent.length);
        console.log('Generated HTML (first 500 chars):', htmlContent.substring(0, 500));
        
        list.innerHTML = htmlContent;
        
        console.log('Notifications displayed successfully in DOM');
        console.log('List element children count:', list.children.length);
    }
    
    // Get icon for notification type
    function getNotificationIcon(type) {
        const icons = {
            'submission_status': '<i class="fas fa-file-check"></i>',
            'submission': '<i class="fas fa-file-upload"></i>',
            'pds_status': '<i class="fas fa-user-check"></i>',
            'pardon_request': '<i class="fas fa-clock"></i>',
            'pardon_approved': '<i class="fas fa-check-circle"></i>',
            'pardon_rejected': '<i class="fas fa-times-circle"></i>',
            'pardon_request_letter': '<i class="fas fa-envelope-open-text"></i>',
            'official_time': '<i class="fas fa-clock"></i>',
            'new_requirement': '<i class="fas fa-tasks"></i>',
            'deadline_reminder': '<i class="fas fa-exclamation-triangle"></i>',
            'announcement': '<i class="fas fa-bullhorn"></i>',
            'system': '<i class="fas fa-cog"></i>'
        };
        return icons[type] || '<i class="fas fa-info-circle"></i>';
    }
    
    // Handle notification click - linkUrl comes from element.dataset.link to avoid escaping issues
    window.handleNotificationClick = function(notifId, element, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const linkUrl = (element && element.dataset && element.dataset.link) ? element.dataset.link : '#';
        
        // Build API URL
        const pathname = window.location.pathname;
        let apiUrl = '../includes/notifications_api.php';
        
        if (pathname.includes('/HR_EVENT/')) {
            apiUrl = '../../includes/notifications_api.php';
        } else if (pathname.includes('/faculty/') || pathname.includes('/admin/')) {
            apiUrl = '../includes/notifications_api.php';
        } else if (pathname.includes('/includes/')) {
            apiUrl = 'notifications_api.php';
        } else {
            const basePath = getApiBaseUrl();
            apiUrl = basePath + '/includes/notifications_api.php';
        }
        
        // Mark as read
        fetch(apiUrl, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: `action=mark_read&notification_id=${notifId}`
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                updateBadgeCount();
                // Close dropdown
                closeDropdownFunc();
                
                // Navigate after a short delay to show feedback
                if (linkUrl && linkUrl !== '#' && linkUrl !== '') {
                    setTimeout(() => {
                        window.location.href = linkUrl;
                    }, 150);
                }
            } else {
                console.error('Failed to mark as read:', data);
            }
        })
        .catch(error => {
            console.error('Error marking notification as read:', error);
        });
    };
    
    // Mark all as read
    if (markAllReadBtn) {
        function markAllRead(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Build API URL
            const pathname = window.location.pathname;
            let apiUrl = '../includes/notifications_api.php';
            
            if (pathname.includes('/faculty/') || pathname.includes('/admin/')) {
                apiUrl = '../includes/notifications_api.php';
            } else if (pathname.includes('/includes/')) {
                apiUrl = 'notifications_api.php';
            } else {
                const basePath = getApiBaseUrl();
                apiUrl = basePath + '/includes/notifications_api.php';
            }
            
            fetch(apiUrl, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: 'action=mark_all_read'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data && data.success) {
                    loadNotifications();
                    updateBadge(0);
                } else {
                    console.error('Failed to mark all as read:', data);
                }
            })
            .catch(error => {
                console.error('Error marking all as read:', error);
            });
        }
        
        markAllReadBtn.addEventListener('click', markAllRead);
        markAllReadBtn.addEventListener('touchend', function(e) {
            if (e.cancelable) {
                e.preventDefault();
                markAllRead(e);
            }
        });
    }
    
    // Update badge
    function updateBadge(count) {
        if (count > 0) {
            if (badge) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'block';
            } else {
                bell.innerHTML += `<span class="notification-badge" id="notificationBadge">${count > 99 ? '99+' : count}</span>`;
            }
        } else {
            if (badge) badge.style.display = 'none';
        }
    }
    
    // Update badge count (refresh)
    function updateBadgeCount() {
        // Build API URL
        const pathname = window.location.pathname;
        let apiUrl = '../includes/notifications_api.php?action=get_count';
        
        if (pathname.includes('/HR_EVENT/')) {
            apiUrl = '../../includes/notifications_api.php?action=get_count';
        } else if (pathname.includes('/faculty/') || pathname.includes('/admin/')) {
            apiUrl = '../includes/notifications_api.php?action=get_count';
        } else if (pathname.includes('/includes/')) {
            apiUrl = 'notifications_api.php?action=get_count';
        } else {
            const basePath = getApiBaseUrl();
            apiUrl = basePath + '/includes/notifications_api.php?action=get_count';
        }
        
        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (response.status === 403 || response.status === 401) {
                return null;
            }
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                updateBadge(data.count || 0);
            }
        })
        .catch(error => {
            // Silently fail for network errors
            const errorMsg = (error && error.message) ? error.message.toLowerCase() : '';
            if (errorMsg.includes('401') || errorMsg.includes('403')) {
                return;
            }
            console.error('Error updating badge count:', error);
        });
    }
    
    // Format time
    function formatTime(datetime) {
        const date = new Date(datetime);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000); // seconds
        
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        
        return date.toLocaleDateString();
    }
    
    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Real-time notification polling - check every 5 seconds
    let lastNotificationId = 0;
    let previousCount = <?php echo $unreadCount; ?>;
    
    // Function to show new notification toast
    function showNotificationToast(title) {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = 'notification-toast';
        toast.innerHTML = `
            <i class="fas fa-bell me-2"></i>
            <span>${escapeHtml(title)}</span>
        `;
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Play notification sound (optional)
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBilz0fPTgjMGHm7A7+OZRQ0PVKvi7KtdFwxEm93wv2gaByZwzPPYfy4HI2q77+iYQw0PUq3j7K1dGQpDmd7ww2wdByx0z/PZgC4IImKz7+aWRA4OTanj7a5fGgpBl93vw24dCCp1z/PagC4II2K07+WWRA4OTqvl7a9fGwpBl93vw28dCCt2z/PagS4IImK17+aWRA4OT6rm7bFfHApBl93vxG8dCCx2z/PbgS4II2K17+aXRQ4OT6nn7bFfHApBl93vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqnn7bBfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bBfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4OTqrm7bFfHApBlt3vxG8dCCx2z/PbgS4II2K17+aXRQ4O');
            audio.volume = 0.3;
            audio.play().catch(() => {}); // Ignore if audio fails
        } catch (e) {}
        
        // Remove after 4 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
    
    // Enhanced update function with new notification detection
    function updateBadgeCountRealtime() {
        // Build API URL
        const pathname = window.location.pathname;
        let apiUrl = '../includes/notifications_api.php?action=get_count&last_id=' + lastNotificationId;
        
        if (pathname.includes('/HR_EVENT/')) {
            // HR_EVENT/admin/ is one level deeper; includes is at project root
            apiUrl = '../../includes/notifications_api.php?action=get_count&last_id=' + lastNotificationId;
        } else if (pathname.includes('/faculty/') || pathname.includes('/admin/')) {
            apiUrl = '../includes/notifications_api.php?action=get_count&last_id=' + lastNotificationId;
        } else if (pathname.includes('/includes/')) {
            apiUrl = 'notifications_api.php?action=get_count&last_id=' + lastNotificationId;
        } else {
            const basePath = getApiBaseUrl();
            apiUrl = basePath + '/includes/notifications_api.php?action=get_count&last_id=' + lastNotificationId;
        }
        
        // Helper function to check if error should be silently ignored
        function shouldIgnoreError(error, response) {
            if (!error && !response) return false;
            // Ignore 403 (Forbidden) and 401 (Unauthorized) errors - likely path/permission issues
            if (response && (response.status === 403 || response.status === 401)) {
                return true;
            }
            if (error) {
                const msg = error.message ? error.message.toLowerCase() : '';
                return msg.includes('403') || msg.includes('forbidden') || 
                       msg.includes('401') || msg.includes('unauthorized') ||
                       msg.includes('failed to fetch') || 
                       msg.includes('blocked') || 
                       msg.includes('networkerror') ||
                       (error.name && error.name === 'TypeError');
            }
            return false;
        }
        
        // Helper function to process notification data
        function processNotificationData(data) {
            if (data.success) {
                const newCount = data.count;
                
                // Check if there are NEW notifications
                if (newCount > previousCount) {
                    // New notification arrived!
                    const diff = newCount - previousCount;
                    
                    // Animate the bell icon
                    const bellIcon = document.querySelector('.notification-bell .bell-icon');
                    if (bellIcon) {
                        bellIcon.classList.add('ring');
                        setTimeout(() => bellIcon.classList.remove('ring'), 1000);
                    }
                    
                    // Show toast notification
                    if (data.latestTitle) {
                        showNotificationToast(data.latestTitle);
                    }
                    
                    // Play notification sound
                    playNotificationSound();
                    
                    // Update the badge with animation
                    updateBadge(newCount);
                    
                    // Flash the badge
                    const badgeElement = document.getElementById('notificationBadge');
                    if (badgeElement) {
                        badgeElement.classList.add('flash');
                        setTimeout(() => badgeElement.classList.remove('flash'), 1000);
                    }
                } else {
                    // Just update the count normally
                    updateBadge(newCount);
                }
                
                previousCount = newCount;
                if (data.lastId) {
                    lastNotificationId = data.lastId;
                }
            }
        }
        
        // Helper function to check if error should be silently ignored
        function shouldIgnoreError(error, response) {
            // Ignore 403 (Forbidden) and 401 (Unauthorized) errors - likely path/permission issues
            if (response && (response.status === 403 || response.status === 401)) {
                return true;
            }
            if (error) {
                const msg = error.message ? error.message.toLowerCase() : '';
                return msg.includes('403') || msg.includes('forbidden') || 
                       msg.includes('401') || msg.includes('unauthorized') ||
                       msg.includes('failed to fetch') || 
                       msg.includes('blocked') || 
                       msg.includes('networkerror') ||
                       (error.name && error.name === 'TypeError');
            }
            return false;
        }
        
        // Fetch notification count
        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            // Check for 403/401 errors and ignore them silently
            if (response.status === 403 || response.status === 401) {
                return Promise.resolve(null);
            }
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data === null) {
                // Permission error was caught, skip processing silently
                return;
            }
            processNotificationData(data);
        })
        .catch(error => {
            // Silently fail for network/permission errors
            const errorMsg = error.message || error.toString() || '';
            const isPermError = errorMsg.includes('403') || errorMsg.includes('Forbidden') ||
                              errorMsg.includes('401') || errorMsg.includes('Unauthorized');
            if (!isPermError) {
                console.error('Error checking notifications:', error);
            }
        });
    }
    
    // Initial check after 2 seconds
    setTimeout(updateBadgeCountRealtime, 2000);
    
    // Real-time polling every 5 seconds (faster than before)
    setInterval(updateBadgeCountRealtime, 5000);
    
    // Also update when window gains focus
    window.addEventListener('focus', updateBadgeCountRealtime);
    
    // Fix notifications page link for mobile compatibility
    (function() {
        const notificationsLink = document.getElementById('notificationsPageLink');
        if (notificationsLink) {
            // Get the current page path
            const pathname = window.location.pathname;
            const userType = notificationsLink.getAttribute('data-user-type') || '';
            
            // Determine correct path based on current location
            let correctPath = 'notifications.php';
            
            if (pathname.includes('/HR_EVENT/')) {
                // HR_EVENT/admin/ - notifications.php is in main admin, two levels up
                correctPath = '../../admin/notifications.php';
            } else if (pathname.includes('/faculty/')) {
                // We're in faculty directory, so notifications.php is in same directory
                correctPath = 'notifications.php';
            } else if (pathname.includes('/admin/')) {
                // We're in admin directory, so notifications.php is in same directory
                correctPath = 'notifications.php';
            } else {
                // We're at root or elsewhere, need to go into the appropriate directory
                if (userType === 'admin') {
                    correctPath = 'admin/notifications.php';
                } else {
                    correctPath = 'faculty/notifications.php';
                }
            }
            
            // Update the href
            notificationsLink.href = correctPath;
            console.log('Notifications link updated to:', correctPath);
        }
    })();
    
    // Function to play notification sound
    function playNotificationSound() {
        try {
            // Try to use actual sound file first
            const baseUrl = getApiBaseUrl();
            const audio = new Audio(baseUrl + '/assets/sounds/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(() => {
                // Fallback to system beep using Web Audio API
                // Only play if audio is initialized
                if (!notificationAudioInitialized || !notificationAudioContext) {
                    // Silently fail - don't log warnings for uninitialized audio
                    return;
                }
                
                try {
                    // Resume audio context if suspended (only if already created)
                    if (notificationAudioContext.state === 'suspended') {
                        notificationAudioContext.resume().then(() => {
                            playNotificationBeep();
                        }).catch(() => {
                            // Silently fail - audio may not be allowed
                        });
                    } else if (notificationAudioContext.state === 'running') {
                        playNotificationBeep();
                    }
                } catch (e) {
                    // Silently fail - audio may not be allowed
                }
            });
        } catch (e) {
            console.log('Notification sound error:', e);
        }
    }
    
    // Helper function to play the beep sound
    function playNotificationBeep() {
        if (!notificationAudioContext) return;
        
        try {
            const oscillator = notificationAudioContext.createOscillator();
            const gainNode = notificationAudioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(notificationAudioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            gainNode.gain.value = 0.3;
            
            oscillator.start(notificationAudioContext.currentTime);
            oscillator.stop(notificationAudioContext.currentTime + 0.2);
        } catch (e) {
            console.log('Could not play notification beep:', e);
        }
    }
})();
</script>
