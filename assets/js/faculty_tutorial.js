/**
 * Faculty First-Use Tutorial System
 * Uses Intro.js for interactive step-by-step guidance with arrows pointing to all features
 */

(function() {
    'use strict';
    
    // Check if Intro.js is loaded
    if (typeof introJs === 'undefined') {
        console.error('Intro.js library not loaded. Please include intro.js in your page.');
        return;
    }
    
    /**
     * Check if device is a mobile phone
     * Returns true if device is mobile (phones, not tablets)
     */
    function isMobileDevice() {
        // Check screen width (mobile phones are typically < 768px)
        const isSmallScreen = window.innerWidth < 768;
        
        // Check user agent for mobile devices
        const userAgent = navigator.userAgent || navigator.vendor || window.opera;
        const isMobileUA = /android|webos|iphone|ipod|blackberry|iemobile|opera mini/i.test(userAgent.toLowerCase());
        
        // Check touch capability (mobile devices usually have touch)
        const hasTouchScreen = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        
        // Consider it mobile if: small screen AND (mobile user agent OR touch screen)
        // But exclude tablets (iPad, Android tablets) which usually have larger screens
        const isTablet = /ipad|android(?!.*mobile)/i.test(userAgent) && window.innerWidth >= 768;
        
        return isSmallScreen && (isMobileUA || hasTouchScreen) && !isTablet;
    }
    
    /**
     * Comprehensive tutorial steps covering ALL features
     * Each step points to a specific feature with an arrow
     */
    const tutorialSteps = [
        // Welcome step
        {
            intro: '<h3>🎉 Welcome to the Faculty Portal!</h3><p>This interactive tutorial will guide you through all the features of the system. Let\'s get started!</p><p><strong>Use the arrow buttons to navigate through each step.</strong></p>'
        },
        
        // Sidebar Navigation - Home Section
        {
            element: '#sidebar',
            intro: '<h3>📋 Navigation Sidebar</h3><p>This is your main navigation panel. All features are organized into sections for easy access.</p>',
            position: 'right'
        },
        {
            element: 'a[href="dashboard.php"]',
            intro: '<h3>🏠 Dashboard</h3><p>The <strong>Dashboard</strong> is your home page. Here you can see an overview of your requirements, submissions, and important information at a glance.</p>',
            position: 'right'
        },
        {
            element: 'a[href="announcements.php"]',
            intro: '<h3>📢 Announcements</h3><p>View important announcements and updates from the administration. Stay informed about deadlines, events, and policy changes.</p>',
            position: 'right'
        },
        {
            element: 'a[href="calendar.php"]',
            intro: '<h3>📅 Calendar</h3><p>Access your personal calendar to view important dates, deadlines, and scheduled events. Never miss an important date!</p>',
            position: 'right'
        },
        
        // My Information Section
        {
            element: 'a[href="profile.php"]',
            intro: '<h3>👤 Profile</h3><p>Manage your personal information, contact details, and profile picture. Keep your information up to date for better communication.</p>',
            position: 'right'
        },
        {
            element: 'a[href="pds.php"]',
            intro: '<h3>📄 Personal Data Sheet (PDS)</h3><p>Fill out and update your Personal Data Sheet. This is an important document that contains your complete professional and personal information required by the administration.</p>',
            position: 'right'
        },
        {
            element: 'a[href="view_logs.php"]',
            intro: '<h3>📊 View Logs</h3><p>View your attendance logs and time records. Track your time in, time out, and working hours. Monitor your attendance history.</p>',
            position: 'right'
        },
        
        // Documents Section
        {
            element: 'a[href="requirements.php"]',
            intro: '<h3>✅ Requirements</h3><p>View all requirements assigned to you by the administration. Submit required documents and track deadlines. Click on any requirement to view details and submit documents.</p>',
            position: 'right'
        },
        {
            element: 'a[href="submissions.php"]',
            intro: '<h3>📤 My Submissions</h3><p>View all your submitted documents. Track the status of your submissions (pending, approved, rejected) and download previously submitted files.</p>',
            position: 'right'
        },
        
        // Settings Section
        {
            element: 'a[href="change_password.php"]',
            intro: '<h3>🔐 Change Password</h3><p>Update your account password regularly to keep your account secure. Use a strong password with a mix of letters, numbers, and symbols.</p>',
            position: 'right'
        },
        
        // Sign Out
        {
            element: '.sidebar-logout-btn',
            intro: '<h3>🚪 Sign Out</h3><p>Click here to securely sign out of your account. Always sign out when you\'re done, especially on shared computers.</p>',
            position: 'top'
        },
        
        // Dashboard Features
        {
            element: '.faculty-hero',
            intro: '<h3>📊 Dashboard Overview</h3><p>Your dashboard shows a personalized welcome message and summary of your active requirements and pending submissions. Use the quick action buttons to navigate to important sections quickly.</p>',
            position: 'bottom'
        },
        {
            element: '.faculty-hero-actions .btn:first-child',
            intro: '<h3>⚡ Quick Actions</h3><p>These buttons provide quick access to frequently used features. Click "View Requirements" to see all your assigned tasks, or "Update PDS" to edit your Personal Data Sheet.</p>',
            position: 'top'
        },
        {
            element: '.faculty-grid .card:first-child',
            intro: '<h3>📋 Assigned Requirements</h3><p>This section displays all requirements assigned to you. Check deadlines and submit documents on time. Click on any requirement row to view details and submit documents. The status badge shows if you\'ve already submitted.</p>',
            position: 'top'
        },
        {
            element: '.faculty-grid .card:nth-child(2)',
            intro: '<h3>📤 My Submissions</h3><p>View all your submitted documents here. Track the status of each submission (Pending, Approved, or Rejected). Click on any submission to view details or download files.</p>',
            position: 'top'
        },
        
        // Mobile Bottom Navigation (if visible)
        {
            element: '.faculty-bottom-nav',
            intro: '<h3>📱 Mobile Navigation</h3><p>On mobile devices, you\'ll see this bottom navigation bar for quick access to the most important features: Home, Tasks, Submissions, and Profile.</p>',
            position: 'top'
        },
        
        // Chat Bubble
        {
            element: '#chatBubble',
            intro: '<h3>💬 Chat with Administrators</h3><p>Use the chat bubble to communicate directly with administrators. Click the chat icon to open messages, send questions, or get help. You\'ll see a badge with unread message count.</p>',
            position: 'left'
        },
        
        // Profile Picture/User Card
        {
            element: '.sidebar-user-card',
            intro: '<h3>👤 Your Profile Card</h3><p>This shows your profile picture, name, and role (Faculty Member or Staff Member). Your profile information is displayed here for quick reference.</p>',
            position: 'right'
        },
        
        // Final step
        {
            intro: '<h3>✅ Tutorial Complete!</h3><p>You\'re all set! You now know how to navigate and use all the features of the Faculty Portal.</p><p><strong>Key Features to Remember:</strong></p><ul style="text-align: left; margin: 10px 0;"><li>📋 Check Requirements regularly and submit on time</li><li>📤 Track your Submissions status</li><li>📊 Monitor your Attendance Logs</li><li>💬 Use Chat to communicate with admins</li><li>📄 Keep your PDS updated</li></ul><p><strong>Note:</strong> This tutorial has been marked as completed for your account and will not show again automatically. You can always access help through the chat feature!</p>'
        }
    ];
    
    /**
     * Initialize and start the tutorial
     */
    function startTutorial() {
        console.log('Starting tutorial...');
        
        // Double-check: Don't start tutorial on mobile devices
        if (isMobileDevice()) {
            console.log('✗ Tutorial start blocked - device is mobile');
            return;
        }
        
        // Wait a bit more to ensure all elements are rendered
        setTimeout(function() {
            try {
                const intro = introJs();
                
                intro.setOptions({
                    steps: tutorialSteps,
                    showProgress: true,
                    showBullets: true,
                    exitOnOverlayClick: false,
                    exitOnEsc: true,
                    keyboardNavigation: true,
                    prevLabel: '← Previous',
                    nextLabel: 'Next →',
                    skipLabel: 'Skip Tutorial',
                    doneLabel: 'Got it!',
                    tooltipClass: 'customTooltip',
                    highlightClass: 'customHighlight',
                    buttonClass: 'btn btn-primary',
                    skipButtonClass: 'btn btn-secondary',
                    // Ensure arrows are visible
                    tooltipPosition: 'auto'
                });
                
                intro.oncomplete(function() {
                    console.log('Tutorial completed by user');
                    cleanupViewportMonitoring();
                    markTutorialAsCompleted();
                });
                
                // Track if exit was from skip button
                let skipButtonClicked = false;
                let tutorialExitedForSkip = false;
                
                // Intercept skip button click before it triggers exit
                intro.onbeforechange(function() {
                    // Check for skip button on each step change
                    setTimeout(function() {
                        const skipButton = document.querySelector('.introjs-skipbutton');
                        if (skipButton && !skipButton.hasAttribute('data-custom-handler')) {
                            skipButton.setAttribute('data-custom-handler', 'true');
                            
                            // Remove default click handler and add our own
                            skipButton.onclick = function(e) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                skipButtonClicked = true;
                                tutorialExitedForSkip = true;
                                
                                // Exit tutorial first to hide all modals/tooltips
                                intro.exit(true);
                                
                                // Wait a bit for tutorial to fully exit, then show confirmation modal
                                setTimeout(function() {
                                    // Show confirmation modal
                                    showTutorialSkipModal(function(confirmed) {
                                        if (confirmed) {
                                            // User confirmed - mark as completed
                                            markTutorialAsCompleted();
                                        } else {
                                            // User cancelled - restart tutorial
                                            skipButtonClicked = false;
                                            tutorialExitedForSkip = false;
                                            // Restart the tutorial
                                            setTimeout(function() {
                                                startTutorial();
                                            }, 300);
                                        }
                                    });
                                }, 300);
                                
                                return false;
                            };
                        }
                    }, 100);
                });
                
                intro.onexit(function() {
                    cleanupViewportMonitoring();
                    
                    // If exit was from skip button, don't do anything here
                    // The confirmation modal will handle the rest
                    if (tutorialExitedForSkip) {
                        tutorialExitedForSkip = false;
                        return;
                    }
                    
                    // Other exit methods (ESC, overlay click) - just clean up
                    // No confirmation needed for these
                    if (skipButtonClicked) {
                        skipButtonClicked = false;
                    }
                });
                
                intro.onchange(function(targetElement) {
                    // Log which step we're on for debugging
                    const currentStep = intro._currentStep;
                    console.log('Tutorial step:', currentStep + 1, 'of', tutorialSteps.length);
                    
                    // Ensure tooltip stays within viewport boundaries
                    setTimeout(function() {
                        ensureTooltipInViewport();
                        // Setup monitoring only once
                        if (!viewportMonitoringSetup) {
                            setupViewportMonitoring();
                        }
                    }, 150);
                });
                
                // Ensure tooltip is positioned correctly on start
                intro.onstart(function() {
                    setTimeout(function() {
                        ensureTooltipInViewport();
                        setupViewportMonitoring();
                    }, 150);
                });
                
                // Clean up monitoring when tutorial ends
                intro.oncomplete(function() {
                    cleanupViewportMonitoring();
                });
                
                intro.onexit(function() {
                    cleanupViewportMonitoring();
                });
                
                console.log('Launching tutorial with', tutorialSteps.length, 'steps');
                intro.start();
            } catch (error) {
                console.error('Error starting tutorial:', error);
                alert('There was an error starting the tutorial. Please refresh the page and try again.');
            }
        }, 2000); // Increased delay to ensure everything is loaded
    }
    
    /**
     * Mark tutorial as completed via API
     */
    function markTutorialAsCompleted() {
        const formData = new FormData();
        formData.append('action', 'complete_tutorial');
        
        fetch('tutorial_api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Tutorial marked as completed - this tutorial will NOT show again for this account');
                // Update the flag to prevent showing again in this session
                window.facultyTutorialCompleted = 1;
                if (document.body) {
                    document.body.setAttribute('data-tutorial-completed', '1');
                }
                
                // Remove the tutorial trigger if it exists
                const tutorialTrigger = document.getElementById('startTutorialBtn');
                if (tutorialTrigger) {
                    tutorialTrigger.style.display = 'none';
                }
            } else {
                console.error('Error marking tutorial as completed:', data.message);
                alert('There was an error saving your tutorial completion status. Please contact support if this persists.');
            }
        })
        .catch(error => {
            console.error('Error marking tutorial as completed:', error);
        });
    }
    
    /**
     * Ensure tooltip stays within viewport boundaries
     * Repositions tooltip if it goes beyond screen edges
     */
    let viewportCheckInterval = null;
    let viewportObserver = null;
    let viewportMonitoringSetup = false;
    
    function ensureTooltipInViewport() {
        const tooltip = document.querySelector('.introjs-tooltip');
        if (!tooltip) {
            // Clean up if tooltip is removed
            if (viewportCheckInterval) {
                clearInterval(viewportCheckInterval);
                viewportCheckInterval = null;
            }
            if (viewportObserver) {
                viewportObserver.disconnect();
                viewportObserver = null;
            }
            return;
        }
        
        // Wait a bit for IntroJS to position the tooltip
        setTimeout(function() {
            const tooltipRect = tooltip.getBoundingClientRect();
            const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            const padding = 15; // Padding from edges
            
            let needsReposition = false;
            let newLeft = parseFloat(tooltip.style.left) || tooltipRect.left;
            let newTop = parseFloat(tooltip.style.top) || tooltipRect.top;
            const tooltipWidth = tooltipRect.width || tooltip.offsetWidth;
            const tooltipHeight = tooltipRect.height || tooltip.offsetHeight;
            
            // If tooltip is floating (no element), CSS handles centering via transform
            // Just ensure it fits within viewport dimensions
            if (tooltip.classList.contains('introjs-floating')) {
                // Floating tooltips are centered by CSS, just check dimensions
                needsReposition = false; // Don't reposition, CSS handles it
            } else {
                // Check horizontal boundaries
                if (tooltipRect.left < padding) {
                    newLeft = padding;
                    needsReposition = true;
                } else if (tooltipRect.right > viewportWidth - padding) {
                    newLeft = Math.max(padding, viewportWidth - tooltipWidth - padding);
                    needsReposition = true;
                }
                
                // Check vertical boundaries
                if (tooltipRect.top < padding) {
                    newTop = padding;
                    needsReposition = true;
                } else if (tooltipRect.bottom > viewportHeight - padding) {
                    newTop = Math.max(padding, viewportHeight - tooltipHeight - padding);
                    needsReposition = true;
                }
            }
            
            // Ensure tooltip doesn't exceed viewport dimensions
            const maxWidth = Math.min(400, viewportWidth - (padding * 2));
            const maxHeight = Math.min(600, viewportHeight - (padding * 2));
            
            // Apply constraints
            if (tooltipWidth > maxWidth) {
                tooltip.style.width = maxWidth + 'px';
            }
            if (tooltipHeight > maxHeight) {
                tooltip.style.maxHeight = maxHeight + 'px';
            }
            
            // Apply new position if needed (only for non-floating tooltips)
            if (!tooltip.classList.contains('introjs-floating')) {
                if (needsReposition || tooltipRect.left < 0 || tooltipRect.top < 0 || 
                    tooltipRect.right > viewportWidth || tooltipRect.bottom > viewportHeight) {
                    tooltip.style.position = 'fixed';
                    tooltip.style.left = newLeft + 'px';
                    tooltip.style.top = newTop + 'px';
                    tooltip.style.right = 'auto';
                    tooltip.style.bottom = 'auto';
                    tooltip.style.margin = '0';
                    tooltip.style.transform = 'none';
                }
            }
            
            // Ensure max dimensions are set for all tooltips
            tooltip.style.maxWidth = maxWidth + 'px';
            tooltip.style.maxHeight = maxHeight + 'px';
        }, 100);
    }
    
    /**
     * Set up continuous viewport monitoring for tooltip
     */
    function setupViewportMonitoring() {
        // Only set up once
        if (viewportMonitoringSetup) return;
        
        const tooltip = document.querySelector('.introjs-tooltip');
        if (!tooltip) return;
        
        viewportMonitoringSetup = true;
        
        // Clean up existing monitoring first (safety)
        if (viewportCheckInterval) {
            clearInterval(viewportCheckInterval);
        }
        if (viewportObserver) {
            viewportObserver.disconnect();
        }
        
        // Periodic check (every 200ms while tutorial is active)
        viewportCheckInterval = setInterval(function() {
            if (document.querySelector('.introjs-tooltip')) {
                ensureTooltipInViewport();
            } else {
                clearInterval(viewportCheckInterval);
                viewportCheckInterval = null;
            }
        }, 200);
        
        // Watch for style changes on tooltip (IntroJS might reposition it)
        viewportObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    setTimeout(ensureTooltipInViewport, 50);
                }
            });
        });
        
        viewportObserver.observe(tooltip, {
            attributes: true,
            attributeFilter: ['style', 'class']
        });
        
        // Handle window resize
        const resizeHandler = function() {
            setTimeout(ensureTooltipInViewport, 50);
        };
        
        window.addEventListener('resize', resizeHandler);
        
        // Handle orientation change on mobile
        const orientationHandler = function() {
            setTimeout(ensureTooltipInViewport, 300);
        };
        
        window.addEventListener('orientationchange', orientationHandler);
        
        // Clean up on tutorial end
        const cleanup = function() {
            if (viewportCheckInterval) {
                clearInterval(viewportCheckInterval);
                viewportCheckInterval = null;
            }
            if (viewportObserver) {
                viewportObserver.disconnect();
                viewportObserver = null;
            }
            window.removeEventListener('resize', resizeHandler);
            window.removeEventListener('orientationchange', orientationHandler);
        };
        
        // Store cleanup function to call later
        tooltip._cleanupViewportMonitoring = cleanup;
    }
    
    /**
     * Clean up viewport monitoring
     */
    function cleanupViewportMonitoring() {
        const tooltip = document.querySelector('.introjs-tooltip');
        if (tooltip && tooltip._cleanupViewportMonitoring) {
            tooltip._cleanupViewportMonitoring();
        }
        if (viewportCheckInterval) {
            clearInterval(viewportCheckInterval);
            viewportCheckInterval = null;
        }
        if (viewportObserver) {
            viewportObserver.disconnect();
            viewportObserver = null;
        }
        viewportMonitoringSetup = false;
    }
    
    /**
     * Show tutorial skip confirmation modal
     * @param {Function} callback - Callback function that receives boolean (confirmed or not)
     */
    function showTutorialSkipModal(callback) {
        // Check if Bootstrap modal is available
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            // Fallback to native confirm if Bootstrap is not available
            const confirmed = confirm('Are you sure you want to skip the tutorial?\n\nNote: This tutorial will only show once per account. Once skipped or completed, it will not appear again automatically.');
            if (callback) callback(confirmed);
            return;
        }
        
        // Get modal element
        const modalElement = document.getElementById('tutorialSkipModal');
        if (!modalElement) {
            // Fallback if modal doesn't exist
            const confirmed = confirm('Are you sure you want to skip the tutorial?\n\nNote: This tutorial will only show once per account. Once skipped or completed, it will not appear again automatically.');
            if (callback) callback(confirmed);
            return;
        }
        
        // Create Bootstrap modal instance
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false
        });
        
        // Remove any existing event listeners
        const confirmBtn = document.getElementById('tutorialSkipConfirmBtn');
        const cancelBtn = document.getElementById('tutorialSkipCancelBtn');
        
        // Create new handler function
        const handleConfirm = function() {
            modal.hide();
            if (callback) callback(true);
        };
        
        const handleCancel = function() {
            modal.hide();
            if (callback) callback(false);
        };
        
        // Set up event listeners
        const newConfirmBtn = document.getElementById('tutorialSkipConfirmBtn');
        const newCancelBtn = document.getElementById('tutorialSkipCancelBtn');
        const closeBtn = modalElement.querySelector('.btn-close');
        
        // Remove old event listeners by cloning and replacing
        if (newConfirmBtn) {
            const confirmClone = newConfirmBtn.cloneNode(true);
            newConfirmBtn.parentNode.replaceChild(confirmClone, newConfirmBtn);
            confirmClone.addEventListener('click', function() {
                modalElement.dataset.confirmed = 'true';
                handleConfirm();
            });
        }
        
        if (newCancelBtn) {
            const cancelClone = newCancelBtn.cloneNode(true);
            newCancelBtn.parentNode.replaceChild(cancelClone, newCancelBtn);
            cancelClone.addEventListener('click', handleCancel);
        }
        
        if (closeBtn) {
            const closeClone = closeBtn.cloneNode(true);
            closeBtn.parentNode.replaceChild(closeClone, closeBtn);
            closeClone.addEventListener('click', handleCancel);
        }
        
        // Handle modal hidden event (in case user clicks backdrop or ESC)
        const handleHidden = function() {
            if (!modalElement.dataset.confirmed) {
                if (callback) callback(false);
            }
            delete modalElement.dataset.confirmed;
            modalElement.removeEventListener('hidden.bs.modal', handleHidden);
        };
        
        modalElement.addEventListener('hidden.bs.modal', handleHidden, { once: true });
        
        // Show modal
        modal.show();
    }
    
    /**
     * Check if tutorial should be shown
     * This ensures the tutorial shows ONLY ONCE per account
     * The status is stored in the database and persists across all sessions
     */
    function shouldShowTutorial() {
        // Check if tutorial_completed flag is set in a data attribute or variable
        // Tutorial should be shown if tutorial_completed is 0 (false)
        // Once completed (set to 1), it will NEVER show again for this account
        
        // Priority 1: Check JavaScript variable (set from PHP)
        const tutorialCompleted = window.facultyTutorialCompleted;
        
        // Priority 2: Check data attribute on body
        const dataAttr = document.body ? document.body.getAttribute('data-tutorial-completed') : null;
        
        // Debug logging
        console.log('=== TUTORIAL CHECK ===');
        console.log('Tutorial Check:', {
            'window.facultyTutorialCompleted': tutorialCompleted,
            'data-tutorial-completed': dataAttr,
            'type of tutorialCompleted': typeof tutorialCompleted
        });
        
        // Show tutorial if tutorial_completed is false (0) - NOT completed
        // This is a one-time per account - once set to 1 (true), it stays 1 forever
        // Logic: if false (0) → show tutorial, if true (1) → don't show
        let shouldShow = false;
        
        if (tutorialCompleted !== undefined && tutorialCompleted !== null) {
            // JavaScript variable is set - check if it's false (0)
            // Show tutorial if: 0, false, or "0"
            shouldShow = (tutorialCompleted === 0 || tutorialCompleted === false || tutorialCompleted === '0');
            console.log('Using window.facultyTutorialCompleted:', tutorialCompleted, '→ shouldShow:', shouldShow);
        } else if (dataAttr !== null) {
            // Data attribute is set - check if it's "0" (false)
            shouldShow = (dataAttr === '0' || dataAttr === 'false');
            console.log('Using data attribute:', dataAttr, '→ shouldShow:', shouldShow);
        } else {
            // Neither is set - default to NOT showing (safety)
            shouldShow = false;
            console.log('Neither variable nor attribute set - defaulting to false');
        }
        
        if (shouldShow) {
            console.log('✓ Tutorial will be shown - this is a one-time guide per account');
        } else {
            console.log('✗ Tutorial already completed for this account - will NOT show again');
        }
        console.log('===================');
        
        return shouldShow;
    }
    
    // Expose functions globally
    window.startFacultyTutorial = startTutorial;
    window.markTutorialAsCompleted = markTutorialAsCompleted;
    
    // Wait for DOM to be ready before checking tutorial status
    function initTutorial() {
        // Re-check tutorial status after DOM is ready
        console.log('=== INITIALIZING TUTORIAL ===');
        
        // Check if device is mobile - if so, skip tutorial
        if (isMobileDevice()) {
            console.log('✗ Tutorial disabled on mobile devices');
            console.log('Device info:', {
                'screen width': window.innerWidth,
                'user agent': navigator.userAgent,
                'touch support': 'ontouchstart' in window
            });
            console.log('===========================');
            return; // Exit early, don't show tutorial on mobile
        }
        
        console.log('Current state:', {
            'window.facultyTutorialCompleted': window.facultyTutorialCompleted,
            'data-attr': document.body ? document.body.getAttribute('data-tutorial-completed') : 'body not ready',
            'body exists': !!document.body,
            'document.readyState': document.readyState,
            'is mobile': isMobileDevice()
        });
        
        // Auto-start tutorial if needed (when page loads)
        // Show tutorial if tutorial_completed is false (0)
        if (shouldShowTutorial()) {
            console.log('✓ Tutorial will start automatically - tutorial_completed is false (0)');
            console.log('Waiting 2 seconds for page to fully load...');
            // Wait a bit for page to fully load (increased delay)
            setTimeout(function() {
                console.log('Starting tutorial now...');
                startTutorial();
            }, 2000);
        } else {
            console.log('✗ Tutorial will NOT start - tutorial_completed is true (1) or not set');
        }
        console.log('===========================');
    }
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOMContentLoaded fired');
            // Add a small delay even after DOMContentLoaded to ensure all scripts are loaded
            setTimeout(initTutorial, 500);
        });
    } else {
        // DOM is already ready
        console.log('DOM already ready');
        setTimeout(initTutorial, 500);
    }
})();
