<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/notifications.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $title = sanitizeInput($_POST['title']);
        $content = sanitizeInput($_POST['content']);
        $priority = $_POST['priority'] ?? 'normal';
        $targetAudience = $_POST['target_audience'] ?? 'all';
        $expiresAt = $_POST['expires_at'] ?? null;
        
        if (empty($title) || empty($content)) {
            $_SESSION['error'] = 'Title and content are required';
        } else {
            try {
                set_time_limit(120); // Allow up to 2 minutes for large recipient lists
                
                // Create announcement
                $stmt = $db->prepare("
                    INSERT INTO announcements (title, content, priority, target_audience, expires_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $content, $priority, $targetAudience, $expiresAt, $_SESSION['user_id']]);
                
                $notificationManager = getNotificationManager();
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                
                $emailQueue = [];
                
                // Phase 1: Insert in-app notifications (fast DB operations)
                if ($targetAudience === 'all' || $targetAudience === 'faculty') {
                    $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE user_type IN ('faculty', 'staff') AND is_verified = 1 AND is_active = 1");
                    $stmt->execute();
                    $faculty = $stmt->fetchAll();
                    $notificationCount = 0;
                    foreach ($faculty as $member) {
                        try {
                            if ($notificationManager->notifyAnnouncement($member['id'], $title, $content, $priority)) {
                                $notificationCount++;
                            } else {
                                error_log("Failed to create notification for user ID: {$member['id']} ({$member['email']})");
                            }
                            $fullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                            if (!empty($member['email'])) {
                                $emailQueue[] = [
                                    'to' => $member['email'],
                                    'toName' => $fullName ?: 'Faculty',
                                    'subject' => "New Announcement: " . $title,
                                    'body' => $mailer->buildAnnouncementEmailBody($fullName, $title, $content),
                                    'isHtml' => true,
                                ];
                            }
                        } catch (Exception $e) {
                            error_log("Error creating notification for user ID {$member['id']}: " . $e->getMessage());
                        }
                    }
                    error_log("Announcement notifications sent to {$notificationCount} faculty/staff members");
                }
                
                if ($targetAudience === 'all' || $targetAudience === 'admin') {
                    $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE user_type IN ('admin', 'super_admin') AND is_active = 1");
                    $stmt->execute();
                    $admins = $stmt->fetchAll();
                    foreach ($admins as $adminUser) {
                        $notificationManager->notifyAnnouncement($adminUser['id'], $title, $content, $priority);
                        $fullName = trim(($adminUser['first_name'] ?? '') . ' ' . ($adminUser['last_name'] ?? ''));
                        if (!empty($adminUser['email'])) {
                            $emailQueue[] = [
                                'to' => $adminUser['email'],
                                'toName' => $fullName ?: 'Admin',
                                'subject' => "New Announcement: " . $title,
                                'body' => $mailer->buildAnnouncementEmailBody($fullName, $title, $content),
                                'isHtml' => true,
                            ];
                        }
                    }
                }
                
                logAction('ANNOUNCEMENT_CREATE', "Created announcement: $title");
                $_SESSION['success'] = 'Announcement created and notifications sent successfully';
                
                // Phase 2: Return response immediately, send emails in background
                if (!empty($emailQueue)) {
                    session_write_close();
                    header('Location: announcements.php');
                    header('Connection: close');
                    header('Content-Length: 0');
                    if (ob_get_level() > 0) ob_end_flush();
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    ignore_user_abort(true);
                    
                    $result = $mailer->sendMailKeepAlive($emailQueue);
                    $sentCount = count($result['sent']);
                    $failedCount = count($result['failed']);
                    if ($failedCount > 0) {
                        error_log("Announcement emails: {$sentCount} sent, {$failedCount} failed");
                    }
                } else {
                    redirect('announcements.php');
                }
                exit;
                
            } catch (Exception $e) {
                error_log("Announcement creation error: " . $e->getMessage());
                $_SESSION['error'] = 'Error creating announcement';
            }
        }
        
    } elseif ($action === 'delete') {
        $id = $_POST['announcement_id'];
        
        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        if ($stmt->execute([$id])) {
            logAction('ANNOUNCEMENT_DELETE', "Deleted announcement ID: $id");
            $_SESSION['success'] = 'Announcement deleted successfully';
        } else {
            $_SESSION['error'] = 'Error deleting announcement';
        }
        redirect('announcements.php');
        
    } elseif ($action === 'toggle_status') {
        $id = $_POST['announcement_id'];
        $isActive = $_POST['is_active'];
        
        $stmt = $db->prepare("UPDATE announcements SET is_active = ? WHERE id = ?");
        if ($stmt->execute([$isActive, $id])) {
            $statusLabel = $isActive ? 'activated' : 'deactivated';
            logAction('ANNOUNCEMENT_STATUS_UPDATE', "{$statusLabel} announcement ID: $id");
            $_SESSION['success'] = 'Announcement status updated';
        } else {
            $_SESSION['error'] = 'Error updating status';
        }
        redirect('announcements.php');
    }
}

// Get all announcements
$stmt = $db->prepare("
    SELECT a.*, u.first_name, u.last_name
    FROM announcements a
    JOIN users u ON a.created_by = u.id
    ORDER BY a.created_at DESC
");
$stmt->execute();
$announcements = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Announcements', 'Create and manage announcements for faculty and staff');
    ?>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Announcements',
                    '',
                    'fas fa-bullhorn',
                    [
                       
                    ],
                    '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal"><i class="fas fa-plus me-1"></i>Create Announcement</button>'
                );
                ?>

                <?php displayMessage(); ?>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2 text-primary"></i>All Announcements
                            <span class="badge bg-primary ms-2"><?php echo count($announcements); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($announcements)): ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <span class="empty-title">No Announcements Yet</span>
                                <p class="mb-0">Create your first announcement to notify faculty members.</p>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Title</th>
                                    <th style="width: 35%;">Preview</th>
                                    <th style="width: 15%;">Posted By</th>
                                    <th style="width: 12%;">Date</th>
                                    <th style="width: 13%;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($announcements as $announcement): ?>
                                    <tr>
                                        <td>
                                            <div class="announcement-title">
                                                <?php echo htmlspecialchars($announcement['title']); ?>
                                                <?php if (!$announcement['is_active']): ?>
                                                    <span class="status-badge inactive">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($announcement['priority'] === 'high' || $announcement['priority'] === 'urgent'): ?>
                                                <span class="priority-badge <?php echo $announcement['priority']; ?>">
                                                    <?php echo $announcement['priority'] === 'high' ? 'High Priority' : 'Urgent'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="announcement-preview">
                                                <?php echo htmlspecialchars($announcement['content']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="posted-by-info">
                                                <i class="fas fa-user posted-by-icon"></i>
                                                <span><?php echo htmlspecialchars($announcement['first_name'] . ' ' . $announcement['last_name']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <?php echo formatDate($announcement['created_at'], 'M j, Y'); ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-primary btn-sm btn-view" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#viewAnnouncementModal<?php echo $announcement['id']; ?>"
                                                        title="View announcement details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm delete-announcement-btn" 
                                                        data-announcement-id="<?php echo $announcement['id']; ?>" 
                                                        data-announcement-title="<?php echo htmlspecialchars($announcement['title']); ?>"
                                                        title="Delete announcement">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Create Announcement Modal -->
    <div class="modal fade" id="createAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title" style="color: white;">
                            <i class="fas fa-bullhorn me-2"></i>Create Announcement
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title *</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="title" name="title" required maxlength="255">
                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Quick templates">
                                    <i class="fas fa-magic me-1"></i>Templates
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="setAnnouncementTemplate('meeting'); return false;"><i class="fas fa-calendar me-2"></i>Meeting Notice</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="setAnnouncementTemplate('deadline'); return false;"><i class="fas fa-clock me-2"></i>Deadline Reminder</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="setAnnouncementTemplate('general'); return false;"><i class="fas fa-bullhorn me-2"></i>General Announcement</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="setAnnouncementTemplate('urgent'); return false;"><i class="fas fa-exclamation-triangle me-2"></i>Urgent Notice</a></li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="content" class="form-label">Content *</label>
                            <textarea class="form-control" id="content" name="content" rows="6" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <option value="low">Low</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="target_audience" class="form-label">Target Audience</label>
                                <select class="form-select" id="target_audience" name="target_audience">
                                    <option value="all">Everyone</option>
                                    <option value="faculty" selected>Faculty Only</option>
                                    <option value="admin">Admin Only</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="expires_at" class="form-label">Expires On (Optional)</label>
                                <input type="date" class="form-control" id="expires_at" name="expires_at">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Create & Send
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Announcement Modals -->
    <?php foreach ($announcements as $announcement): ?>
        <div class="modal fade" id="viewAnnouncementModal<?php echo $announcement['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" style="color: white;">
                    <i class="fas fa-bullhorn me-2"></i><?php echo htmlspecialchars($announcement['title']); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php if (!$announcement['is_active']): ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                                <?php if ($announcement['priority'] === 'urgent'): ?>
                                    <span class="badge bg-danger">Urgent</span>
                                <?php elseif ($announcement['priority'] === 'high'): ?>
                                    <span class="badge bg-warning text-dark">High Priority</span>
                                <?php elseif ($announcement['priority'] === 'normal'): ?>
                                    <span class="badge bg-info">Normal Priority</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Low Priority</span>
                                <?php endif; ?>
                                <span class="badge bg-primary"><?php echo ucfirst($announcement['target_audience']); ?></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2">Content:</h6>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-muted small">
                            <div class="col-md-6">
                                <p class="mb-1">
                                    <i class="fas fa-user me-2"></i>
                                    <strong>Created By:</strong> <?php echo $announcement['first_name'] . ' ' . $announcement['last_name']; ?>
                                </p>
                                <p class="mb-1">
                                    <i class="far fa-clock me-2"></i>
                                    <strong>Date:</strong> <?php echo formatDate($announcement['created_at'], 'M j, Y g:i A'); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1">
                                    <i class="fas fa-users me-2"></i>
                                    <strong>Audience:</strong> <?php echo ucfirst($announcement['target_audience']); ?>
                                </p>
                                <?php if ($announcement['expires_at']): ?>
                                    <p class="mb-1">
                                        <i class="fas fa-hourglass-end me-2"></i>
                                        <strong>Expires:</strong> <?php echo formatDate($announcement['expires_at'], 'M j, Y'); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <div>
                            <button type="button" class="btn btn-danger delete-announcement-btn" 
                                    data-announcement-id="<?php echo $announcement['id']; ?>" 
                                    data-announcement-title="<?php echo htmlspecialchars($announcement['title']); ?>"
                                    data-bs-toggle="tooltip" 
                                    data-bs-placement="top" 
                                    title="Delete this announcement">
                                <i class="fas fa-trash me-2"></i>Delete
                            </button>
                            <form method="POST" class="d-inline ms-2">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $announcement['is_active'] ? 0 : 1; ?>">
                                <button type="submit" class="btn btn-<?php echo $announcement['is_active'] ? 'warning' : 'success'; ?>"
                                        data-bs-toggle="tooltip" 
                                        data-bs-placement="top" 
                                        title="<?php echo $announcement['is_active'] ? 'Deactivate this announcement' : 'Activate this announcement'; ?>">
                                    <i class="fas fa-<?php echo $announcement['is_active'] ? 'pause' : 'play'; ?> me-2"></i>
                                    <?php echo $announcement['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteAnnouncementModal" tabindex="-1" aria-labelledby="deleteAnnouncementModalLabel" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteAnnouncementModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this announcement?</p>
                    <div class="alert alert-warning mb-3">
                        <strong><i class="fas fa-bullhorn me-2"></i>Title:</strong> 
                        <span id="deleteAnnouncementTitle"></span>
                    </div>
                    <p class="text-danger mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>This action cannot be undone!</strong>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteAnnouncementBtn">
                        <i class="fas fa-trash me-1"></i>Delete Announcement
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 10000;">
        <!-- Toasts will be dynamically inserted here -->
    </div>
    
    <?php admin_page_scripts(); ?>
    
    <style>
        /* Enhanced button group styling */
        .btn-group .btn {
            transition: all 0.2s ease;
        }
        
        .btn-group .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .btn-group .btn:active {
            transform: translateY(0);
        }
        
        /* Delete button hover effect */
        .delete-announcement-btn:hover {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }
        
        /* Table row transition for smooth deletion */
        table tbody tr {
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
        }
        
        /* Loading spinner animation */
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Toast container styling */
        .toast-container {
            max-width: 400px;
        }
        
        /* Delete modal styling */
        #deleteAnnouncementModal .modal-header {
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }
        
        #deleteAnnouncementModal .alert-warning {
            border-left: 4px solid #ffc107;
        }
        
        /* Button group spacing */
        .btn-group .btn-sm {
            padding: 0.25rem 0.5rem;
        }
        
        /* Smooth modal transitions */
        .modal.fade .modal-dialog {
            transition: transform 0.3s ease-out;
        }
    </style>
    
    <script>
        let deleteModalInstance;
        let announcementToDelete = null;
        let deleteButtonElement = null;
        let deleteRowElement = null;
        
        // Announcement templates
        function setAnnouncementTemplate(type) {
            const templates = {
                'meeting': {
                    title: 'Faculty Meeting Scheduled',
                    content: 'Dear Faculty Members,\n\nThis is to inform you that a faculty meeting has been scheduled.\n\nDate: [Date]\nTime: [Time]\nVenue: [Location]\n\nPlease confirm your attendance.\n\nThank you.'
                },
                'deadline': {
                    title: 'Important Deadline Reminder',
                    content: 'Dear Faculty Members,\n\nThis is a reminder regarding an upcoming deadline.\n\nDeadline: [Date]\nTask: [Description]\n\nPlease ensure all submissions are completed on time.\n\nThank you for your cooperation.'
                },
                'general': {
                    title: 'General Announcement',
                    content: 'Dear Faculty Members,\n\n[Your announcement content here]\n\nThank you.'
                },
                'urgent': {
                    title: 'URGENT: Immediate Action Required',
                    content: 'URGENT NOTICE\n\nDear Faculty Members,\n\nThis is an urgent announcement requiring immediate attention.\n\n[Details]\n\nPlease take necessary action as soon as possible.\n\nThank you.'
                }
            };
            
            if (templates[type]) {
                document.getElementById('title').value = templates[type].title;
                document.getElementById('content').value = templates[type].content;
                if (type === 'urgent') {
                    document.getElementById('priority').value = 'urgent';
                }
                document.getElementById('content').focus();
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            // For buttons with modal triggers, we need to initialize tooltips manually
            document.querySelectorAll('.btn-view[title]').forEach(function(element) {
                new bootstrap.Tooltip(element, { trigger: 'hover' });
            });
            
            // Initialize tooltips for delete buttons and other buttons with tooltip attribute
            document.querySelectorAll('.delete-announcement-btn[title], button[data-bs-toggle="tooltip"][title]').forEach(function(element) {
                new bootstrap.Tooltip(element);
            });
            
            // Initialize delete confirmation modal
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteAnnouncementModal'));
            
            // Add event listeners to all delete buttons
            document.querySelectorAll('.delete-announcement-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const id = this.getAttribute('data-announcement-id');
                    const title = this.getAttribute('data-announcement-title');
                    
                    // Find the table row if this is from the table
                    const row = this.closest('tr');
                    if (row) {
                        deleteRowElement = row;
                    }
                    
                    // Store references
                    announcementToDelete = id;
                    deleteButtonElement = this;
                    
                    // Update modal content
                    document.getElementById('deleteAnnouncementTitle').textContent = title;
                    
                    // Show confirmation modal
                    deleteModalInstance.show();
                });
            });
            
            // Handle confirm delete button
            document.getElementById('confirmDeleteAnnouncementBtn').addEventListener('click', function() {
                if (announcementToDelete) {
                    performDelete(announcementToDelete);
                }
            });
        });
        
        function performDelete(id) {
            const confirmBtn = document.getElementById('confirmDeleteAnnouncementBtn');
            const originalHTML = confirmBtn.innerHTML;
            
            // Disable button and show loading state
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
            
            // Also disable the original delete button if it exists
            if (deleteButtonElement) {
                deleteButtonElement.disabled = true;
                const originalBtnHTML = deleteButtonElement.innerHTML;
                deleteButtonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('announcement_id', id);
            
            fetch('announcement_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Check if response is ok
                if (!response.ok) {
                    // If not ok, try to get error message or use status text
                    return response.text().then(text => {
                        try {
                            const json = JSON.parse(text);
                            return json;
                        } catch (e) {
                            // If not JSON, return error object
                            throw new Error(`Server error (${response.status}): ${response.statusText}`);
                        }
                    });
                }
                // If ok, parse JSON
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Close delete confirmation modal
                    deleteModalInstance.hide();
                    
                    // Close view modal if open
                    const viewModalElement = document.getElementById('viewAnnouncementModal' + id);
                    if (viewModalElement) {
                        const viewModal = bootstrap.Modal.getInstance(viewModalElement);
                        if (viewModal) {
                            viewModal.hide();
                        }
                    }
                    
                    // Show success toast
                    showToast('success', data.message, 'check-circle');
                    
                    // Animate row removal if deleting from table
                    if (deleteRowElement) {
                        deleteRowElement.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
                        deleteRowElement.style.opacity = '0';
                        deleteRowElement.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            deleteRowElement.remove();
                            
                            // Update announcement count badge
                            const badge = document.querySelector('.badge.bg-primary');
                            if (badge) {
                                const currentCount = parseInt(badge.textContent) || 0;
                                badge.textContent = Math.max(0, currentCount - 1);
                            }
                            
                            // Check if table is now empty
                            const tbody = document.querySelector('table tbody');
                            if (tbody && tbody.children.length === 0) {
                                window.location.reload();
                            }
                        }, 300);
                    } else {
                        // Reload page if deleting from modal
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    }
                } else {
                    // Show error toast
                    showToast('danger', data.message, 'exclamation-circle');
                    
                    // Re-enable buttons
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalHTML;
                    if (deleteButtonElement) {
                        deleteButtonElement.disabled = false;
                        deleteButtonElement.innerHTML = '<i class="fas fa-trash"></i>';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('danger', 'An error occurred while deleting the announcement.', 'exclamation-triangle');
                
                // Re-enable buttons
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = originalHTML;
                if (deleteButtonElement) {
                    deleteButtonElement.disabled = false;
                    deleteButtonElement.innerHTML = '<i class="fas fa-trash"></i>';
                }
            });
        }
        
        function showToast(type, message, icon = 'info-circle') {
            const toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) return;
            
            const toastId = 'toast-' + Date.now();
            const bgClass = type === 'success' ? 'bg-success' : type === 'danger' ? 'bg-danger' : 'bg-info';
            const iconClass = type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            const toastHTML = `
                <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas ${iconClass} me-2"></i>
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHTML);
            
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: 5000
            });
            
            toast.show();
            
            // Remove toast element after it's hidden
            toastElement.addEventListener('hidden.bs.toast', function() {
                toastElement.remove();
            });
        }
    </script>
</body>
</html>

