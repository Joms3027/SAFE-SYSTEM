<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();
$userId = $_SESSION['user_id'];

// Get faculty submissions
$stmt = $db->prepare("
    SELECT s.*, r.title as requirement_title, r.description as requirement_description
    FROM faculty_submissions s
    JOIN requirements r ON s.requirement_id = r.id
    WHERE s.faculty_id = ?
    ORDER BY s.submitted_at DESC
");
$stmt->execute([$userId]);
$submissions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Suppress 403/401 errors from chat and notifications APIs - must be early -->
    <script>
        (function() {
            const originalError = console.error;
            const originalWarn = console.warn;
            const originalLog = console.log;
            
            // Override console.error to filter 403/401 errors
            console.error = function(...args) {
                const message = args.join(' ').toLowerCase();
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized') ||
                    message.includes('chat_api') || message.includes('notifications_api') ||
                    message.includes('api error: undefined')) {
                    return; // Silently ignore
                }
                originalError.apply(console, args);
            };
            
            // Override console.warn
            console.warn = function(...args) {
                const message = args.join(' ').toLowerCase();
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized')) {
                    return; // Silently ignore
                }
                originalWarn.apply(console, args);
            };
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <title>My Submissions - WPU Faculty and Staff System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/submissions.css', true); ?>" rel="stylesheet">
    <!-- CRITICAL: Override Bootstrap modals to prevent blocking buttons -->
    <link href="<?php echo asset_url('css/bootstrap-modal-override.css', true); ?>" rel="stylesheet">
    <style>
        /* CRITICAL FIX: Ensure Bootstrap modals don't block buttons when closed */
        body:not(.modal-open) .modal,
        body:not(.modal-open) .modal.fade,
        body:not(.modal-open) .modal:not(.show) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            position: fixed !important;
            top: -99999px !important;
            left: -99999px !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* CRITICAL FIX: Ensure Bootstrap backdrops don't block buttons when modals are closed */
        .modal-backdrop:not(.show),
        .modal-backdrop[style*="display: none"],
        body:not(.modal-open) .modal-backdrop,
        body:not(.modal-open) .modal-backdrop.fade {
            display: none !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            position: fixed !important;
        }
        
        /* Ensure modals are only interactive when showing */
        .modal.show,
        body.modal-open .modal.show {
            pointer-events: auto !important;
            z-index: 1055 !important;
        }
        
        /* Ensure backdrops are only interactive when showing */
        .modal-backdrop.show,
        body.modal-open .modal-backdrop.show {
            pointer-events: auto !important;
            z-index: 1040 !important;
        }
        
        /* CRITICAL: Force Bootstrap modals to be non-blocking when closed - MUST be before button rules */
        body:not(.modal-open) .modal,
        body:not(.modal-open) .modal.fade,
        body:not(.modal-open) .modal:not(.show) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            position: fixed !important;
            top: -99999px !important;
            left: -99999px !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Ensure buttons are always clickable ABOVE Bootstrap when modals are closed */
        body:not(.modal-open) .submission-actions {
            z-index: 1099 !important;
            position: relative !important;
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0) !important;
        }
        
        body:not(.modal-open) .submission-actions .btn,
        body:not(.modal-open) .btn-action,
        body:not(.modal-open) button:not(.modal button):not(:disabled),
        body:not(.modal-open) .btn:not(.modal .btn):not(:disabled) {
            z-index: 1100 !important; /* Above Bootstrap's 1055 */
            position: relative !important;
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0) !important; /* Force new stacking context */
        }
        
        /* CRITICAL: Ensure main content is not blocked by hidden modals */
        body:not(.modal-open) .main-content,
        body:not(.modal-open) .main-content *:not(.modal):not(.modal *) {
            position: relative !important;
            z-index: auto !important;
            pointer-events: auto !important;
        }
        
        /* Ensure submission cards don't create stacking context issues */
        .submission-card {
            position: relative;
            z-index: 1;
        }
        
        .submission-card .submission-actions {
            z-index: 1099 !important;
        }
        
        /* CRITICAL: Ensure chat bubble and toggle menu are always clickable */
        .chat-bubble-container,
        .chat-toggle-btn,
        .header .menu-toggle,
        .menu-toggle {
            z-index: 10000 !important;
            position: relative !important;
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0) !important;
        }
        
        .chat-bubble-container {
            position: fixed !important;
            z-index: 10000 !important;
        }
        
        .header .menu-toggle {
            z-index: 1031 !important;
            position: relative !important;
            pointer-events: auto !important;
        }
        
        .chat-toggle-btn {
            z-index: 10001 !important;
            pointer-events: auto !important;
            cursor: pointer !important;
        }
        
        .chat-window {
            z-index: 10000 !important;
            pointer-events: auto !important;
        }
        
        /* CRITICAL: Ensure chat window buttons are always clickable */
        .chat-minimize-btn,
        .chat-close-btn,
        .chat-sound-test-btn {
            z-index: 10002 !important;
            pointer-events: auto !important;
            cursor: pointer !important;
            position: relative !important;
            touch-action: manipulation !important;
        }
        
        .chat-header-actions {
            z-index: 10002 !important;
            pointer-events: auto !important;
        }
        
        .chat-modal {
            z-index: 10001 !important;
        }
        
        /* CRITICAL: Ensure chat modal close button is always clickable */
        .chat-modal-close {
            z-index: 10004 !important;
            pointer-events: auto !important;
            cursor: pointer !important;
            position: relative !important;
            touch-action: manipulation !important;
            isolation: isolate !important;
            -webkit-user-select: none !important;
            user-select: none !important;
        }
        
        .chat-modal-header {
            z-index: 10003 !important;
            pointer-events: auto !important;
            position: relative !important;
        }
        
        .chat-modal-content {
            z-index: 10002 !important;
            pointer-events: auto !important;
            isolation: isolate !important;
        }
        
        /* Ensure chat modal backdrop doesn't block content */
        .chat-modal {
            z-index: 10001 !important;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 767px) {
            html, body {
                height: auto !important;
                min-height: 100% !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }

            .container-fluid {
                min-height: auto !important;
                height: auto !important;
            }

            .main-content {
                padding: 0.75rem !important;
                padding-bottom: 2rem !important;
                min-height: auto !important;
                height: auto !important;
                overflow: visible !important;
                position: relative;
                z-index: 1;
            }

            .d-flex.justify-content-between {
                margin-bottom: 1rem !important;
            }

            .h2 {
                font-size: 1.5rem !important;
            }

            .card-header h5 {
                font-size: 1rem;
            }

            .submissions-grid {
                padding-bottom: 1rem;
            }
            
            /* CRITICAL: Hide Bootstrap modals completely when closed on mobile */
            body:not(.modal-open) .modal,
            body:not(.modal-open) .modal.fade,
            body:not(.modal-open) .modal:not(.show) {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                z-index: -9999 !important;
                position: fixed !important;
                top: -99999px !important;
                left: -99999px !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* CRITICAL: Ensure buttons are clickable on mobile ABOVE Bootstrap */
            .submission-actions {
                z-index: 1099 !important;
                position: relative !important;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            .submission-actions .btn,
            .btn-action,
            button:not(.modal button):not(:disabled),
            .btn:not(.modal .btn):not(:disabled) {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                touch-action: manipulation !important;
                isolation: isolate !important;
                transform: translateZ(0) !important; /* Force new stacking context */
            }
            
            /* Ensure main content is not blocked */
            .main-content,
            .main-content *:not(.modal):not(.modal *) {
                pointer-events: auto !important;
            }
            
            /* CRITICAL: Ensure chat bubble and toggle menu are clickable on mobile */
            .chat-bubble-container,
            .chat-toggle-btn,
            .header .menu-toggle,
            .menu-toggle {
                z-index: 10000 !important;
                position: relative !important;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            .chat-bubble-container {
                position: fixed !important;
                z-index: 10000 !important;
            }
            
            .header .menu-toggle {
                z-index: 1031 !important;
                position: relative !important;
                pointer-events: auto !important;
            }
            
            .chat-toggle-btn {
                z-index: 10001 !important;
                pointer-events: auto !important;
                cursor: pointer !important;
                touch-action: manipulation !important;
            }
            
            .chat-window {
                z-index: 10000 !important;
                pointer-events: auto !important;
            }
            
            /* CRITICAL: Ensure chat window buttons are always clickable on mobile */
            .chat-minimize-btn,
            .chat-close-btn,
            .chat-sound-test-btn {
                z-index: 10002 !important;
                pointer-events: auto !important;
                cursor: pointer !important;
                position: relative !important;
                touch-action: manipulation !important;
            }
            
            .chat-header-actions {
                z-index: 10002 !important;
                pointer-events: auto !important;
            }
            
            .chat-modal {
                z-index: 10001 !important;
            }
        }

        @supports (height: 100dvh) {
            @media (max-width: 767px) {
                html, body {
                    min-height: 100dvh !important;
                }
            }
        }
        
        /* Remove excessive padding from Cancel button in modals */
        .btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        .btn-outline-secondary[data-bs-dismiss="modal"] {
            padding: 0.375rem 0.75rem !important;
        }
    </style>
</head>
<body class="layout-faculty submissions-page">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            
            <main class="main-content">
                <div class="page-header submissions-page-header">
                    <div class="page-title">
                        <i class="fas fa-upload" aria-hidden="true"></i>
                        <span>My Submissions</span>
                    </div>
                   
                </div>

                <?php displayMessage(); ?>

                <?php if (!empty($submissions)): ?>
                <section class="submissions-stats" aria-label="Filter submissions">
                    <div class="submissions-filters" role="group" aria-label="Filter by status">
                        <button type="button" class="filter-chip active" data-filter="all" aria-pressed="true">All</button>
                        <button type="button" class="filter-chip" data-filter="pending" aria-pressed="false">Pending</button>
                        <button type="button" class="filter-chip" data-filter="approved" aria-pressed="false">Approved</button>
                        <button type="button" class="filter-chip" data-filter="rejected" aria-pressed="false">Rejected</button>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Submissions List -->
                <div class="card submissions-card">
                    <div class="card-header submissions-card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-list me-2" aria-hidden="true"></i>File Submissions <span class="submissions-count">(<?php echo count($submissions); ?>)</span></h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submissions)): ?>
                            <div class="empty-state submissions-empty">
                                <div class="empty-state-icon" aria-hidden="true">
                                    <i class="fas fa-upload"></i>
                                </div>
                                <h3 class="empty-state-title">No Submissions Yet</h3>
                                <p class="empty-state-text">Submit files for your assigned requirements from the Requirements page.</p>
                                <a href="requirements.php" class="btn btn-primary btn-empty-cta">
                                    <i class="fas fa-folder-open me-2" aria-hidden="true"></i>View Requirements
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="submissions-grid" role="list">
                                <?php foreach ($submissions as $submission): ?>
                                    <article class="submission-card" role="listitem" data-status="<?php echo htmlspecialchars($submission['status']); ?>">
                                        <div class="submission-header">
                                            <div class="submission-title">
                                                <?php echo htmlspecialchars($submission['requirement_title'] ?? ''); ?>
                                            </div>
                                            <?php if (!empty($submission['requirement_description'])): ?>
                                                <div class="submission-desc">
                                                    <?php echo htmlspecialchars($submission['requirement_description'] ?? ''); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="submission-body">
                                            <div class="submission-item">
                                                <i class="fas fa-file"></i>
                                                <div class="submission-item-content">
                                                    <div class="submission-item-label">File Name</div>
                                                    <div class="submission-item-value">
                                                        <?php echo htmlspecialchars($submission['original_filename'] ?? ''); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="submission-item">
                                                <i class="fas fa-hdd"></i>
                                                <div class="submission-item-content">
                                                    <div class="submission-item-label">File Size</div>
                                                    <div class="submission-item-value">
                                                        <?php echo formatFileSize($submission['file_size']); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="submission-item">
                                                <i class="fas fa-circle-check"></i>
                                                <div class="submission-item-content">
                                                    <div class="submission-item-label">Status</div>
                                                    <div class="submission-item-value">
                                                        <span class="badge bg-<?php 
                                                            echo $submission['status'] === 'approved' ? 'success' : 
                                                                ($submission['status'] === 'rejected' ? 'danger' : 'warning'); 
                                                        ?>">
                                                            <?php echo ucfirst($submission['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="submission-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                <div class="submission-item-content">
                                                    <div class="submission-item-label">Submitted</div>
                                                    <div class="submission-item-value">
                                                        <?php echo formatDate($submission['submitted_at'], 'M j, Y g:i A'); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="submission-item">
                                                <i class="fas fa-user-check"></i>
                                                <div class="submission-item-content">
                                                    <div class="submission-item-label">Reviewed</div>
                                                    <div class="submission-item-value">
                                                        <?php if ($submission['reviewed_at']): ?>
                                                            <?php echo formatDate($submission['reviewed_at'], 'M j, Y g:i A'); ?>
                                                        <?php else: ?>
                                                            <span style="color: #9ca3af;">Not reviewed</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="submission-actions">
                                            <a href="download_submission.php?file=<?php echo rawurlencode($submission['file_path'] ?? ''); ?>&name=<?php echo rawurlencode($submission['original_filename'] ?? ''); ?>" 
                                               class="btn btn-success" title="Download">
                                                <i class="fas fa-download me-1"></i>Download
                                            </a>
                                            <?php if ($submission['admin_notes']): ?>
                                                <button type="button" class="btn btn-primary" 
                                                        onclick="showNotes('<?php echo htmlspecialchars($submission['admin_notes']); ?>')" 
                                                        title="View Notes">
                                                    <i class="fas fa-comment me-1"></i>Notes
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="submissions-bottom-spacer" aria-hidden="true"></div>
            </main>
        </div>
    </div>

    <!-- Notes Modal - fullscreen on mobile for better UX -->
    <div class="modal fade" id="notesModal" tabindex="-1" aria-labelledby="notesModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notesModalTitle">Admin Notes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body submissions-notes-body" id="notesContent">
                    <!-- Notes content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary btn-close-notes" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        // Additional suppression (redundant but ensures coverage)
        (function() {
            const originalError = console.error;
            const originalWarn = console.warn;
            
            if (console.error !== originalError) {
                // Already overridden in head, just enhance it
                const currentError = console.error;
                console.error = function(...args) {
                    const message = args.join(' ').toLowerCase();
                    if (message.includes('403') || message.includes('forbidden') ||
                        message.includes('401') || message.includes('unauthorized') ||
                        message.includes('chat_api') || message.includes('notifications_api') ||
                        message.includes('api error: undefined') ||
                        message.includes('get ') && (message.includes('chat_api') || message.includes('notifications_api'))) {
                        return; // Silently ignore
                    }
                    currentError.apply(console, args);
                };
            }
        })();
        
        function showNotes(notes) {
            var content = document.getElementById('notesContent');
            var safe = (notes || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/\n/g, '<br>');
            content.innerHTML = '<p class="mb-0">' + safe + '</p>';
            new bootstrap.Modal(document.getElementById('notesModal')).show();
        }
        
        // Filter submissions by status (mobile-friendly chips) - event delegation so chips always work
        document.addEventListener('DOMContentLoaded', function() {
            var filtersEl = document.querySelector('.submissions-page .submissions-filters');
            var cards = document.querySelectorAll('.submissions-page .submission-card');
            var countEl = document.querySelector('.submissions-page .submissions-count');
            if (!filtersEl || !cards.length) return;

            filtersEl.addEventListener('click', function(e) {
                var chip = e.target && e.target.closest && e.target.closest('.filter-chip');
                if (!chip) return;
                e.stopPropagation();
                var filter = chip.getAttribute('data-filter');
                if (!filter) return;
                var chips = filtersEl.querySelectorAll('.filter-chip');
                chips.forEach(function(c) {
                    var isActive = c.getAttribute('data-filter') === filter;
                    c.classList.toggle('active', isActive);
                    c.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                var visible = 0;
                cards.forEach(function(card) {
                    var show = filter === 'all' || card.getAttribute('data-status') === filter;
                    card.classList.toggle('submission-card-hidden', !show);
                    card.setAttribute('aria-hidden', show ? 'false' : 'true');
                    if (show) visible++;
                });
                if (countEl) countEl.textContent = '(' + visible + ')';
            });
        });
        
        // CRITICAL FIX: Clean up Bootstrap backdrops that might block buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Clean up any leftover modal backdrops when modals are closed
            function cleanupBackdrops() {
                const body = document.body;
                const backdrops = document.querySelectorAll('.modal-backdrop');
                
                // If body doesn't have modal-open class, remove all backdrops
                if (!body || !body.classList) return;
                if (!body.classList.contains('modal-open')) {
                    backdrops.forEach(function(backdrop) {
                        if (!backdrop.classList.contains('show')) {
                            backdrop.remove();
                        }
                    });
                }
            }
            
            // Clean up on page load
            cleanupBackdrops();
            
            // Watch for modal close events
            document.querySelectorAll('.modal').forEach(function(modal) {
                modal.addEventListener('hidden.bs.modal', function() {
                    setTimeout(cleanupBackdrops, 100);
                });
            });
            
            // Also clean up periodically (in case Bootstrap doesn't fire events)
            setInterval(cleanupBackdrops, 500);
            
            // CRITICAL: Ensure toggle menu and chat bubble are always clickable
            function fixNavigationButtons() {
                // Fix menu toggle button - only fix styles, don't interfere with existing handlers
                const menuToggles = document.querySelectorAll('.menu-toggle, .header .menu-toggle');
                menuToggles.forEach(function(toggle) {
                    toggle.style.pointerEvents = 'auto';
                    toggle.style.touchAction = 'manipulation';
                    toggle.style.cursor = 'pointer';
                    toggle.style.zIndex = '1031';
                    toggle.style.position = 'relative';
                });
                
                // Fix chat bubble button - only fix styles, don't interfere with existing handlers
                const chatToggleBtn = document.getElementById('chatToggleBtn');
                if (chatToggleBtn) {
                    chatToggleBtn.style.pointerEvents = 'auto';
                    chatToggleBtn.style.touchAction = 'manipulation';
                    chatToggleBtn.style.cursor = 'pointer';
                    chatToggleBtn.style.zIndex = '10001';
                    chatToggleBtn.style.position = 'relative';
                }
                
                // Fix chat bubble container
                const chatContainer = document.querySelector('.chat-bubble-container');
                if (chatContainer) {
                    chatContainer.style.pointerEvents = 'auto';
                    chatContainer.style.zIndex = '10000';
                    chatContainer.style.position = 'fixed';
                }
                
                // Fix chat window buttons (minimize, close, sound test) - only fix styles, don't interfere with onclick handlers
                const chatWindowButtons = document.querySelectorAll('.chat-minimize-btn, .chat-close-btn, .chat-sound-test-btn');
                chatWindowButtons.forEach(function(btn) {
                    btn.style.pointerEvents = 'auto';
                    btn.style.touchAction = 'manipulation';
                    btn.style.cursor = 'pointer';
                    btn.style.zIndex = '10002';
                    btn.style.position = 'relative';
                });
                
                // Ensure chat window itself is clickable
                const chatWindow = document.getElementById('chatWindow');
                if (chatWindow) {
                    chatWindow.style.pointerEvents = 'auto';
                    chatWindow.style.zIndex = '10000';
                }
                
                // CRITICAL: Fix chat modal close button - ensure it's always clickable
                const chatModalCloseBtn = document.querySelector('.chat-modal-close');
                if (chatModalCloseBtn) {
                    chatModalCloseBtn.style.pointerEvents = 'auto';
                    chatModalCloseBtn.style.zIndex = '10004';
                    chatModalCloseBtn.style.position = 'relative';
                    chatModalCloseBtn.style.cursor = 'pointer';
                    chatModalCloseBtn.style.touchAction = 'manipulation';
                    
                    // Ensure click handler works
                    chatModalCloseBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        if (typeof closeNewChatModal === 'function') {
                            closeNewChatModal();
                        }
                    }, { capture: true });
                    
                    chatModalCloseBtn.addEventListener('touchend', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (typeof closeNewChatModal === 'function') {
                            closeNewChatModal();
                        }
                    }, { capture: true, passive: false });
                }
            }
            
            // CRITICAL: Ensure ALL buttons are clickable with proper z-index
            function fixButtons() {
                document.querySelectorAll('button:not(:disabled), .btn:not(:disabled), a.btn:not(.disabled), .submission-actions .btn, .submission-actions button, .submission-actions a, .btn-action').forEach(function(button) {
                    // Skip buttons inside open modals
                    if (button.closest('.modal.show')) {
                        return;
                    }
                    
                    // Skip navigation buttons (handled separately)
                    if (button.classList.contains('menu-toggle') || button.id === 'chatToggleBtn') {
                        return;
                    }
                    
                    // Skip chat window buttons (minimize, close, sound test) - handled separately
                    if (button.classList.contains('chat-minimize-btn') || 
                        button.classList.contains('chat-close-btn') || 
                        button.classList.contains('chat-sound-test-btn') ||
                        button.classList.contains('chat-modal-close')) {
                        return;
                    }
                    
                    // Skip buttons inside chat window or chat modal to avoid interference
                    if (button.closest('.chat-window') || 
                        button.closest('.chat-bubble-container') ||
                        button.closest('.chat-modal') ||
                        button.closest('#newChatModal')) {
                        return;
                    }
                    
                    // Skip filter chips - they have their own handler and must not get inline styles or extra listeners
                    if (button.classList.contains('filter-chip')) {
                        return;
                    }
                    
                    // Ensure button is fully clickable with high z-index
                    button.style.pointerEvents = 'auto';
                    button.style.touchAction = 'manipulation';
                    button.style.cursor = 'pointer';
                    button.style.position = 'relative';
                    button.style.zIndex = '1000';
                    
                    // Stop event propagation from parent to button
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
            
            // Fix navigation buttons first
            fixNavigationButtons();
            
            // Fix buttons on load
            fixButtons();
            
            // Fix buttons when content changes
            const observer = new MutationObserver(function() {
                cleanupBackdrops();
                fixNavigationButtons();
                fixButtons();
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });
        });
    </script>
</body>
</html>







