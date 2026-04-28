<?php
// Shared Notifications Page for both Admin and Faculty
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}

$notificationManager = getNotificationManager();
$userId = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging
    error_log("=== NOTIFICATIONS PAGE POST DEBUG ===");
    error_log("POST data: " . json_encode($_POST));
    error_log("User ID: " . $userId);
    
    // Clear any previous messages to prevent duplicates
    unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning'], $_SESSION['info']);
    
    // Determine the redirect URL - use relative path like other pages
    $redirectUrl = 'notifications.php';
    
    // Add filter parameter if it exists
    if (isset($_GET['filter'])) {
        $redirectUrl .= '?filter=' . urlencode($_GET['filter']);
    }
    
    error_log("Redirect URL: " . $redirectUrl);
    
    if (isset($_POST['mark_read'])) {
        $notifId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        error_log("Marking notification as read: ID = $notifId, User = $userId");
        
        if ($notifId <= 0) {
            $_SESSION['error'] = 'Invalid notification ID';
            error_log("Invalid notification ID: $notifId");
        } else {
            $result = $notificationManager->markAsRead($notifId, $userId);
            if ($result) {
                $_SESSION['success'] = 'Notification marked as read';
                error_log("Successfully marked notification $notifId as read for user $userId");
            } else {
                $_SESSION['error'] = 'Failed to mark notification as read. Please try again.';
                error_log("Failed to mark notification $notifId as read for user $userId");
            }
        }
        
        // Redirect back to notifications page
        header('Location: ' . $redirectUrl);
        exit;
    } elseif (isset($_POST['delete'])) {
        $notifId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        error_log("Removing notification: ID = $notifId, User = $userId");
        
        if ($notifId <= 0) {
            $_SESSION['error'] = 'Invalid notification ID';
            error_log("Invalid notification ID: $notifId");
        } else {
            try {
                $result = $notificationManager->deleteNotification($notifId, $userId);
                if ($result) {
                    $_SESSION['success'] = 'Notification removed';
                    error_log("Successfully removed notification $notifId for user $userId");
                } else {
                    $_SESSION['error'] = 'Failed to remove notification. The notification may not exist or you may not have permission to remove it.';
                    error_log("Failed to remove notification $notifId for user $userId - deleteNotification returned false");
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error removing notification: ' . htmlspecialchars($e->getMessage());
                error_log("Exception removing notification $notifId for user $userId: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        }
        // Add cache-busting parameter to ensure fresh data
        $separator = strpos($redirectUrl, '?') !== false ? '&' : '?';
        header('Location: ' . $redirectUrl . $separator . '_=' . time());
        exit;
    } elseif (isset($_POST['mark_all_read'])) {
        error_log("Marking all notifications as read for user: $userId");
        
        if ($notificationManager->markAllAsRead($userId)) {
            $_SESSION['success'] = 'All notifications marked as read';
            error_log("Successfully marked all as read");
        } else {
            $_SESSION['error'] = 'Failed to mark all notifications as read';
            error_log("Failed to mark all as read");
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// Get notifications
if ($filter === 'unread') {
    $notifications = $notificationManager->getUnreadNotifications($userId, $perPage * $page);
} else {
    $notifications = $notificationManager->getAllNotifications($userId, $perPage * $page);
}

$unreadCount = $notificationManager->getUnreadCount($userId);

$layoutClass = '';
if (!empty($_SESSION['user_type'])) {
    $layoutClass = $_SESSION['user_type'] === 'faculty' || $_SESSION['user_type'] === 'staff'
        ? 'layout-faculty'
        : (isAdmin() ? 'layout-admin' : '');
}
$isFacultyLayout = ($layoutClass === 'layout-faculty');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <meta name="description" content="Notifications - WPU Faculty and Staff Management System">
    <title>Notifications - WPU Faculty and Staff System</title>
    <?php
    // Get base path for assets (works from any subdirectory)
    // Use getBasePath() function for consistent, portable path detection
    if (!function_exists('getBasePath')) {
        require_once __DIR__ . '/functions.php';
    }
    $basePath = getBasePath();
    // Ensure basePath has leading slash for consistency
    if ($basePath && $basePath !== '/' && strpos($basePath, '/') !== 0) {
        $basePath = '/' . $basePath;
    }
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <?php if ($isFacultyLayout): ?>
        <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <?php endif; ?>
    <style>
        :root {
            --notification-radius: 16px;
            --notification-border: rgba(148, 163, 184, 0.25);
            --notification-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        .notifications-main {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: clamp(1rem, 2vw + 1rem, 2.5rem);
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            background: transparent;
        }

        .notifications-header {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.5rem;
            padding: 1.5rem;
            border-radius: var(--notification-radius);
            border: 1px solid var(--notification-border);
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.08) 0%, rgba(14, 165, 233, 0.04) 100%);
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
        }

        .notifications-heading {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .notifications-heading .h2 {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin: 0;
            font-size: clamp(1.35rem, 1.2vw + 1.1rem, 1.85rem);
        }

        .notifications-heading p {
            margin: 0;
            color: #475569;
            font-size: 0.95rem;
        }

        .notifications-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-left: auto;
        }

        .notifications-actions .btn {
            border-radius: 999px;
            padding-inline: 1.2rem;
            font-weight: 600;
            box-shadow: 0 10px 16px rgba(2, 132, 199, 0.15);
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .notifications-actions .btn i {
            font-size: 0.95rem;
        }

        .notifications-stack {
            display: grid;
            gap: 1.25rem;
            width: 100%;
        }

        .notification-card {
            background: white;
            border-radius: var(--notification-radius);
            border: 1px solid rgba(226, 232, 240, 0.9);
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
            overflow: hidden;
            box-shadow: 0 8px 16px rgba(15, 23, 42, 0.05);
            backdrop-filter: blur(6px);
        }
        
        .notification-card:hover {
            box-shadow: var(--notification-shadow);
            transform: translateY(-3px);
            border-color: rgba(2, 132, 199, 0.35);
        }
        
        .notification-card.unread {
            background: linear-gradient(180deg, rgba(224, 242, 254, 0.65) 0%, #ffffff 38%);
            border-left: 4px solid #0284c7;
            animation: cardPulse 1.2s ease both;
        }
        
        .notification-card.priority-high {
            border-left: 4px solid #dc2626;
        }
        
        .notification-row {
            display: flex;
            align-items: center;
            padding: 1rem 1.25rem;
            gap: 1rem;
        }
        
        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        
        .notification-icon.submission_status { background: #e0f2fe; color: #0284c7; }
        .notification-icon.pds_status { background: #e0e7ff; color: #6366f1; }
        .notification-icon.pardon_request { background: #fef3c7; color: #d97706; }
        .notification-icon.new_requirement { background: #fef3c7; color: #d97706; }
        .notification-icon.deadline_reminder { background: #fee2e2; color: #dc2626; }
        .notification-icon.announcement { background: #ddd6fe; color: #7c3aed; }
        .notification-icon.system { background: #e5e7eb; color: #6b7280; }
        
        .notification-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }
        
        .notification-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 1rem;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        
        .notification-message {
            color: #6b7280;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }
        
        .notification-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        
        .notification-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .notification-actions .btn-action {
            padding: 0.45rem 0.9rem;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            font-weight: 600;
        }
        
        .filter-tabs {
            background: white;
            border-radius: 14px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid rgba(226, 232, 240, 0.9);
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .filter-tabs::-webkit-scrollbar {
            display: none;
        }

        .filter-tabs .nav {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.75rem;
            min-width: max-content;
        }

        .filter-tabs .nav-item {
            flex: 0 0 auto;
        }
        
        .filter-tabs .nav-link {
            color: #6b7280;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 600;
        }
        
        .filter-tabs .nav-link:hover {
            background: #f3f4f6;
        }
        
        .filter-tabs .nav-link.active {
            background: #0284c7;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 8px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1.5rem;
        }
        
        .btn-action {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            border-radius: 6px;
            transition: all 0.2s;
            font-weight: 600;
        }

        .notifications-empty {
            border-radius: var(--notification-radius);
            border: 1px dashed rgba(148, 163, 184, 0.4);
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.95) 0%, #ffffff 65%);
            box-shadow: inset 0 0 0 1px rgba(226, 232, 240, 0.45);
        }

        @keyframes cardPulse {
            0% { transform: translateY(0); box-shadow: 0 6px 12px rgba(2, 132, 199, 0.16); }
            60% { transform: translateY(-2px); box-shadow: 0 14px 24px rgba(2, 132, 199, 0.12); }
            100% { transform: translateY(0); box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08); }
        }
        
        @media (max-width: 768px) {
            .notifications-main {
                padding: 1rem 1.1rem 2rem;
            }

            .notifications-header {
                padding: 1.1rem 1.25rem;
                border-radius: 14px;
            }

            .notifications-actions {
                width: 100%;
                justify-content: stretch;
            }

            .notifications-actions .btn {
                flex: 1;
                justify-content: center;
                box-shadow: none;
            }

            .filter-tabs {
                margin-left: -1.1rem;
                margin-right: -1.1rem;
                border-radius: 0;
                border-width: 1px 0;
            }

            .notification-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .notification-actions {
                width: 100%;
                justify-content: stretch;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
            
            .notification-meta {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .notification-message {
                font-size: 0.95rem;
            }
        }

        @media (min-width: 992px) {
            .notifications-stack {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .notification-card,
            .notification-card:hover,
            .notification-card.unread {
                transition: none;
                animation: none;
            }
        }
        
        /* Remove Notification Modal Styles */
        #removeNotificationModal {
            display: flex !important;
            align-items: center;
            justify-content: center;
            padding: 0 !important;
            z-index: 1060 !important; /* Higher than backdrop (1040) */
        }
        
        #removeNotificationModal.show {
            z-index: 1060 !important;
        }
        
        body.modal-open #removeNotificationModal {
            z-index: 1060 !important;
        }
        
        body.modal-open #removeNotificationModal.show {
            z-index: 1060 !important;
        }
        
        /* Ensure modal backdrop doesn't block the modal */
        body.modal-open .modal-backdrop {
            z-index: 1040 !important;
        }
        
        body.modal-open .modal-backdrop.show {
            z-index: 1040 !important;
        }
        
        /* Prevent multiple backdrops from stacking */
        .modal-backdrop:not(:last-of-type) {
            display: none !important;
        }
        
        /* Ensure only the last backdrop is visible */
        body.modal-open .modal-backdrop:not(:last-of-type) {
            display: none !important;
            z-index: -1 !important;
        }
        
        /* Ensure modal content is above backdrop */
        #removeNotificationModal .modal-dialog {
            max-width: 500px;
            width: 100%;
            margin: 1rem auto;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: auto;
            z-index: 1061 !important;
            position: relative;
        }
        
        #removeNotificationModal .modal-content {
            z-index: 1062 !important;
            position: relative;
            width: 100%;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        #removeNotificationModal .modal-dialog-centered {
            min-height: calc(100% - 1rem);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Ensure buttons in modal are clickable */
        #removeNotificationModal .modal-content button,
        #removeNotificationModal .modal-content .btn {
            z-index: 1063 !important;
            position: relative;
            pointer-events: auto !important;
        }
        
        #removeNotificationModal .remove-notification-icon {
            animation: iconPulse 2s ease-in-out infinite;
        }
        
        #removeNotificationModal .btn-danger {
            transition: all 0.3s ease;
        }
        
        #removeNotificationModal .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4) !important;
        }
        
        #removeNotificationModal .btn-danger:active {
            transform: translateY(0);
        }
        
        #removeNotificationModal .btn-outline-secondary {
            transition: all 0.3s ease;
        }
        
        #removeNotificationModal .btn-outline-secondary:hover {
            background-color: #f3f4f6;
            border-color: #9ca3af;
            transform: translateY(-1px);
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        @keyframes iconPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 8px 16px rgba(220, 38, 38, 0.2);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 10px 20px rgba(220, 38, 38, 0.3);
            }
        }
        
        @media (max-width: 576px) {
            #removeNotificationModal .modal-dialog {
                margin: 0.5rem auto;
                max-width: calc(100% - 1rem);
                min-height: calc(100% - 1rem);
            }
            
            #removeNotificationModal .modal-header {
                padding: 1.5rem 1.25rem 0.75rem !important;
            }
            
            #removeNotificationModal .remove-notification-icon {
                width: 56px !important;
                height: 56px !important;
            }
            
            #removeNotificationModal .remove-notification-icon i {
                font-size: 1.5rem !important;
            }
            
            #removeNotificationModal .modal-title {
                font-size: 1.15rem !important;
            }
            
            #removeNotificationModal .modal-body {
                padding: 1rem 1.25rem 0.75rem !important;
            }
            
            #removeNotificationModal .modal-footer {
                flex-direction: column;
                padding: 0.75rem 1.25rem 1.25rem !important;
            }
            
            #removeNotificationModal .modal-footer .btn {
                width: 100%;
                margin: 0;
            }
        }
    </style>
</head>
<body<?php echo $layoutClass ? ' class="' . $layoutClass . '"' : ''; ?>>
    <?php require_once __DIR__ . '/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php
                $mainClasses = ['main-content'];
                if ($isFacultyLayout) {
                    $mainClasses[] = 'faculty-notifications-page';
                } else {
                    $mainClasses[] = 'notifications-main';
                }
            ?>
            <main class="<?php echo implode(' ', $mainClasses); ?>">
                <div class="page-header">
                    <div>
                        <?php if ($isFacultyLayout): ?>
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb mb-1">
                                    <li class="breadcrumb-item active" aria-current="page">
                                        <i class="fas fa-bell me-1"></i>Notifications
                                    </li>
                                </ol>
                            </nav>
                        <?php endif; ?>
                        <div class="page-title">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                        </div>
                        <p class="page-subtitle">
                            Stay updated on requirement changes, deadlines, and important announcements.
                        </p>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" 
                                    id="markAllReadBtn"
                                    class="btn btn-primary <?php echo $isFacultyLayout ? 'mark-all-btn' : 'btn-sm'; ?>">
                                <i class="fas fa-check-double<?php echo $isFacultyLayout ? '' : ' me-2'; ?>"></i>
                                <?php echo $isFacultyLayout ? 'Mark All as Read' : '<span class="d-none d-sm-inline">Mark All as Read</span>'; ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php displayMessage(); ?>

                <!-- Filter Tabs -->
                <div class="<?php echo $isFacultyLayout ? 'card faculty-notifications-filter filter-tabs' : 'filter-tabs'; ?>">
                    <?php if ($isFacultyLayout): ?>
                        <div class="card-body">
                    <?php endif; ?>
                            <ul class="nav nav-pills mb-0">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">
                                        <i class="fas fa-list me-1"></i>All
                                        <span class="badge bg-<?php echo $filter === 'all' ? 'light text-primary' : 'secondary'; ?> ms-1">
                                            <?php echo count($notifications); ?>
                                        </span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $filter === 'unread' ? 'active' : ''; ?>" href="?filter=unread">
                                        <i class="fas fa-envelope me-1"></i>Unread
                                        <span class="badge bg-<?php echo $filter === 'unread' ? 'light text-primary' : 'secondary'; ?> ms-1">
                                            <?php echo $unreadCount; ?>
                                        </span>
                                    </a>
                                </li>
                            </ul>
                    <?php if ($isFacultyLayout): ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Notifications List -->
                <?php if (empty($notifications)): ?>
                    <div class="<?php echo $isFacultyLayout ? 'faculty-notifications-empty' : 'empty-state notifications-empty'; ?>">
                        <i class="fas fa-bell-slash"></i>
                        <h4 class="text-muted mb-2">No notifications</h4>
                        <p class="text-muted mb-0">You're all caught up!</p>
                    </div>
                <?php else: ?>
                    <div class="<?php echo $isFacultyLayout ? 'faculty-notification-list' : 'notifications-stack'; ?>">
                        <?php foreach ($notifications as $notif): ?>
                            <?php
                                $cardClasses = $isFacultyLayout
                                    ? ['card', 'faculty-notification-card']
                                    : ['notification-card'];
                                if ($notif['is_read'] == 0) {
                                    $cardClasses[] = 'unread';
                                }
                                if (in_array(($notif['priority'] ?? 'normal'), ['high', 'urgent'], true)) {
                                    $cardClasses[] = 'priority-high';
                                }
                            ?>
                            <div class="<?php echo implode(' ', $cardClasses); ?>" data-notification-id="<?php echo (int)$notif['id']; ?>">
                                <div class="notification-row">
                                    <div class="notification-row-header">
                                        <div class="notification-icon <?php echo htmlspecialchars($notif['type']); ?>">
                                            <?php
                                            $icons = [
                                                'submission_status' => 'file-check',
                                                'pds_status' => 'user-check',
                                                'pardon_request' => 'clock',
                                                'new_requirement' => 'tasks',
                                                'deadline_reminder' => 'exclamation-triangle',
                                                'announcement' => 'bullhorn',
                                                'system' => 'cog'
                                            ];
                                            $icon = $icons[$notif['type']] ?? 'info-circle';
                                            ?>
                                            <i class="fas fa-<?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <div class="notification-title">
                                                <?php echo htmlspecialchars($notif['title']); ?>
                                                <?php if ($notif['is_read'] == 0): ?>
                                                    <span class="badge bg-primary" style="font-size: 0.7rem;">New</span>
                                                <?php endif; ?>
                                                <?php if (in_array(($notif['priority'] ?? 'normal'), ['high', 'urgent'], true)): ?>
                                                    <span class="badge bg-danger" style="font-size: 0.7rem;">High Priority</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notification-message">
                                                <?php echo nl2br(htmlspecialchars($notif['message'])); ?>
                                            </div>
                                            <div class="notification-meta">
                                                <span>
                                                    <i class="far fa-clock me-1"></i><?php echo timeAgo($notif['created_at']); ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-tag me-1"></i><?php echo ucfirst(str_replace('_', ' ', $notif['type'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="notification-actions">
                                        <?php if (!empty($notif['link_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($notif['link_url']); ?>"
                                               class="<?php echo $isFacultyLayout ? 'btn btn-primary btn-action' : 'btn btn-sm btn-primary btn-action'; ?>"
                                               title="View details">
                                                <i class="fas fa-arrow-right"></i>
                                                <?php if ($isFacultyLayout): ?><span>Open</span><?php endif; ?>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($notif['is_read'] == 0): ?>
                                            <form method="POST" class="notification-action-form notification-mark-read-form" 
                                                  data-notification-id="<?php echo (int)$notif['id']; ?>"
                                                  onsubmit="return handleNotificationMarkRead(event, this);">
                                                <input type="hidden" name="notification_id" value="<?php echo (int)$notif['id']; ?>">
                                                <button type="submit"
                                                        name="mark_read"
                                                        value="1"
                                                        class="<?php echo $isFacultyLayout ? 'btn btn-success btn-action' : 'btn btn-sm btn-success btn-action'; ?>"
                                                        title="Mark as read">
                                                    <i class="fas fa-check"></i>
                                                    <?php if ($isFacultyLayout): ?><span>Mark Read</span><?php endif; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" class="notification-action-form notification-delete-form" 
                                              data-notification-id="<?php echo (int)$notif['id']; ?>"
                                              onsubmit="return handleNotificationRemove(event, this);">
                                            <input type="hidden" name="notification_id" value="<?php echo (int)$notif['id']; ?>">
                                            <button type="submit"
                                                    name="delete"
                                                    value="1"
                                                    class="<?php echo $isFacultyLayout ? 'btn btn-danger btn-action' : 'btn btn-sm btn-danger btn-action'; ?>"
                                                    title="Remove">
                                                <i class="fas fa-eye-slash"></i>
                                                <?php if ($isFacultyLayout): ?><span>Remove</span><?php endif; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        // Get API URL - use relative path for better compatibility
        function getApiUrl() {
            return '../includes/notifications_api.php';
        }
        
        // Handle notification mark as read with AJAX
        function handleNotificationMarkRead(event, form) {
            event.preventDefault();
            event.stopPropagation();
            
            const notificationId = form.dataset.notificationId || form.querySelector('input[name="notification_id"]').value;
            const button = form.querySelector('button[type="submit"]');
            const icon = button.querySelector('i');
            const originalIconClass = icon.className;
            
            // Find the notification card
            let notificationCard = form.closest('.notification-card, .faculty-notification-card, .card');
            if (!notificationCard) {
                notificationCard = document.querySelector('[data-notification-id="' + notificationId + '"]');
            }
            
            // Show loading state
            button.disabled = true;
            icon.className = 'fas fa-spinner fa-spin';
            if (button.querySelector('span')) {
                button.querySelector('span').textContent = 'Marking...';
            }
            
            // Get API URL
            const apiUrl = getApiUrl();
            
            // Make AJAX request
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: 'action=mark_read&notification_id=' + encodeURIComponent(notificationId)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data && data.success) {
                    // Successfully marked as read - update UI
                    if (notificationCard && notificationCard.classList) {
                        // Remove unread class
                        notificationCard.classList.remove('unread');
                        
                        // Remove "New" badge if it exists
                        const newBadge = notificationCard.querySelector('.badge.bg-primary');
                        if (newBadge && newBadge.textContent && newBadge.textContent.trim() === 'New') {
                            newBadge.remove();
                        }
                        
                        // Remove the mark as read button
                        if (form) {
                            form.style.transition = 'opacity 0.3s ease';
                            form.style.opacity = '0';
                            setTimeout(() => {
                                if (form.parentNode) {
                                    form.remove();
                                }
                            }, 300);
                        }
                    } else {
                        // Fallback: reload page
                        window.location.reload();
                    }
                } else {
                    // Error - show message and restore button
                    const errorMsg = (data && data.message) ? data.message : 'Unknown error';
                    alert('Failed to mark notification as read: ' + errorMsg);
                    button.disabled = false;
                    icon.className = originalIconClass;
                    if (button.querySelector('span')) {
                        button.querySelector('span').textContent = 'Mark Read';
                    }
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
                alert('An error occurred while marking the notification as read. Please try again.');
                button.disabled = false;
                icon.className = originalIconClass;
                if (button.querySelector('span')) {
                    button.querySelector('span').textContent = 'Mark Read';
                }
            });
            
            return false;
        }
        
        // Show styled confirmation modal for removing notification
        function showRemoveNotificationModal(notificationId, form) {
            // Clean up any existing backdrops first
            const existingBackdrops = document.querySelectorAll('.modal-backdrop');
            existingBackdrops.forEach(backdrop => {
                if (backdrop && backdrop.parentNode) {
                    backdrop.remove();
                }
            });
            
            // Remove modal-open class if it exists
            if (document.body && document.body.classList) {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
            
            // Remove any existing modal with the same ID
            const existingModal = document.getElementById('removeNotificationModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Create modal element
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'removeNotificationModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'removeNotificationModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.zIndex = '1060';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); margin: auto; z-index: 1062; position: relative;">
                        <div class="modal-header border-0 pb-0" style="padding: 2rem 2rem 1rem;">
                            <div class="d-flex align-items-center gap-3 w-100">
                                <div class="remove-notification-icon" style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 16px rgba(220, 38, 38, 0.2);">
                                    <i class="fas fa-exclamation-triangle" style="font-size: 1.75rem; color: #dc2626;"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="modal-title mb-1" id="removeNotificationModalLabel" style="font-size: 1.35rem; font-weight: 700; color: #1f2937;">
                                        Remove Notification
                                    </h5>
                                    <p class="mb-0" style="font-size: 0.9rem; color: #6b7280;">
                                        This action cannot be undone
                                    </p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="margin: 0; z-index: 1064; position: relative;"></button>
                            </div>
                        </div>
                        <div class="modal-body px-4 pb-3" style="padding-top: 1rem;">
                            <p class="mb-0" style="font-size: 1rem; color: #475569; line-height: 1.6;">
                                Are you sure you want to remove this notification? It will be hidden from your notifications list.
                            </p>
                        </div>
                        <div class="modal-footer border-0 pt-2 pb-3 px-4" style="gap: 0.75rem;">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 10px; padding: 0.6rem 1.5rem; font-weight: 600; border-width: 2px; z-index: 1064; position: relative;">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmRemoveBtn" style="border-radius: 10px; padding: 0.6rem 1.5rem; font-weight: 600; background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); border: none; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); z-index: 1064; position: relative;">
                                <i class="fas fa-trash-alt me-2"></i>Remove Notification
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Add modal to body
            document.body.appendChild(modal);
            
            // Initialize Bootstrap modal with options to prevent backdrop issues
            const bsModal = new bootstrap.Modal(modal, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            
            // Ensure modal and buttons are above backdrop when shown
            modal.addEventListener('shown.bs.modal', function() {
                // Force z-index after modal is shown
                modal.style.zIndex = '1060';
                const modalDialog = modal.querySelector('.modal-dialog');
                if (modalDialog) {
                    modalDialog.style.zIndex = '1061';
                }
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.style.zIndex = '1062';
                }
                
                // Ensure all buttons are clickable
                const buttons = modal.querySelectorAll('button, .btn');
                buttons.forEach(btn => {
                    btn.style.zIndex = '1064';
                    btn.style.position = 'relative';
                    btn.style.pointerEvents = 'auto';
                });
                
                // Ensure backdrop is below modal
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    backdrop.style.zIndex = '1040';
                });
            });
            
            // Show the modal
            bsModal.show();
            
            // Handle confirm button click
            const confirmBtn = modal.querySelector('#confirmRemoveBtn');
            confirmBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close modal
                bsModal.hide();
                
                // Proceed with removal after a short delay to ensure modal is closed
                setTimeout(() => {
                    proceedWithRemoval(notificationId, form);
                }, 300);
            });
            
            // Clean up when modal is hidden
            modal.addEventListener('hidden.bs.modal', function() {
                // Clean up any remaining backdrops
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => {
                    if (backdrop && backdrop.parentNode) {
                        backdrop.remove();
                    }
                });
                
                // Remove modal-open class
                if (document.body && document.body.classList) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
                
                // Remove modal from DOM
                if (document.body.contains(modal)) {
                    document.body.removeChild(modal);
                }
            });
        }
        
        // Proceed with notification removal after confirmation
        function proceedWithRemoval(notificationId, form) {
            const button = form.querySelector('button[type="submit"]');
            const icon = button.querySelector('i');
            const originalIconClass = icon.className;
            
            // Try multiple methods to find the notification card
            let notificationCard = form.closest('.notification-card, .faculty-notification-card, .card');
            if (!notificationCard) {
                // Fallback: find by data attribute
                notificationCard = document.querySelector('[data-notification-id="' + notificationId + '"]');
            }
            if (!notificationCard) {
                // Last resort: find parent container
                notificationCard = form.closest('div[class*="notification"]') || form.parentElement?.parentElement?.parentElement;
            }
            
            // Show loading state
            button.disabled = true;
            icon.className = 'fas fa-spinner fa-spin';
            if (button.querySelector('span')) {
                button.querySelector('span').textContent = 'Removing...';
            }
            
            // Get API URL
            const apiUrl = getApiUrl();
            
            // Make AJAX request
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: 'action=delete&notification_id=' + encodeURIComponent(notificationId)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data && data.success) {
                    // Successfully removed - animate and remove the card
                    if (notificationCard) {
                        // Add fade-out animation
                        notificationCard.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        notificationCard.style.opacity = '0';
                        notificationCard.style.transform = 'translateX(-20px)';
                        
                        // Remove from DOM after animation
                        setTimeout(() => {
                            notificationCard.remove();
                            
                            // Check if no notifications left
                            const notificationsContainer = document.querySelector('.notifications-stack, .faculty-notification-list');
                            if (notificationsContainer && notificationsContainer.children.length === 0) {
                                // Show empty state
                                const emptyState = document.createElement('div');
                                emptyState.className = '<?php echo $isFacultyLayout ? 'faculty-notifications-empty' : 'empty-state notifications-empty'; ?>';
                                emptyState.innerHTML = `
                                    <i class="fas fa-bell-slash"></i>
                                    <h4 class="text-muted mb-2">No notifications</h4>
                                    <p class="text-muted mb-0">You're all caught up!</p>
                                `;
                                notificationsContainer.parentElement.appendChild(emptyState);
                            }
                        }, 300);
                    } else {
                        // Fallback: reload page
                        window.location.reload();
                    }
                } else {
                    // Error - show message and restore button
                    const errorMsg = (data && data.message) ? data.message : 'Unknown error';
                    alert('Failed to remove notification: ' + errorMsg);
                    button.disabled = false;
                    icon.className = originalIconClass;
                    if (button.querySelector('span')) {
                        button.querySelector('span').textContent = 'Remove';
                    }
                }
            })
            .catch(error => {
                console.error('Error removing notification:', error);
                alert('An error occurred while removing the notification. Please try again.');
                button.disabled = false;
                icon.className = originalIconClass;
                if (button.querySelector('span')) {
                    button.querySelector('span').textContent = 'Remove';
                }
            });
        }
        
        // Handle notification remove with AJAX
        function handleNotificationRemove(event, form) {
            event.preventDefault();
            event.stopPropagation();
            
            const notificationId = form.dataset.notificationId || form.querySelector('input[name="notification_id"]').value;
            
            // Show styled confirmation modal
            showRemoveNotificationModal(notificationId, form);
            
            return false;
        }
        
        // Enhanced form submission handling for notifications
        document.addEventListener('DOMContentLoaded', function() {
            // Handle notification action forms (but skip delete and mark-read forms that use AJAX)
            document.querySelectorAll('.notification-action-form:not(.notification-delete-form):not(.notification-mark-read-form)').forEach(form => {
                if (!form || !form.classList) return;
                
                form.addEventListener('submit', function(e) {
                    // Prevent double submission
                    if (!this || !this.classList) {
                        e.preventDefault();
                        return false;
                    }
                    
                    if (this.classList.contains('submitting')) {
                        e.preventDefault();
                        return false;
                    }
                    
                    this.classList.add('submitting');
                    const btn = this.querySelector('button[type="submit"]');
                    if (btn) {
                        btn.disabled = true;
                        const icon = btn.querySelector('i');
                        if (icon) {
                            icon.className = 'fas fa-spinner fa-spin';
                        }
                    }
                    
                    // Allow form to submit normally
                    return true;
                });
            });
            
            // Handle "Mark All as Read" button
            const markAllReadBtn = document.getElementById('markAllReadBtn');
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function() {
                    const button = this;
                    const icon = button.querySelector('i');
                    const originalIconClass = icon.className;
                    const originalText = button.innerHTML;
                    
                    // Show loading state
                    button.disabled = true;
                    icon.className = 'fas fa-spinner fa-spin';
                    if (button.querySelector('span')) {
                        button.querySelector('span').textContent = 'Marking...';
                    } else {
                        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';
                    }
                    
                    // Get API URL
                    const apiUrl = getApiUrl();
                    
                    // Make AJAX request
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
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.success) {
                            // Successfully marked all as read - update UI
                            const notificationCards = document.querySelectorAll('.notification-card.unread, .faculty-notification-card.unread, .card.unread');
                            
                            notificationCards.forEach(card => {
                                // Skip if card is null
                                if (!card || !card.classList) return;
                                
                                // Remove unread class
                                card.classList.remove('unread');
                                
                                // Remove "New" badges
                                const newBadges = card.querySelectorAll('.badge.bg-primary');
                                newBadges.forEach(badge => {
                                    if (badge && badge.textContent && badge.textContent.trim() === 'New') {
                                        badge.remove();
                                    }
                                });
                                
                                // Remove mark as read buttons
                                const markReadForms = card.querySelectorAll('.notification-mark-read-form');
                                markReadForms.forEach(form => {
                                    if (form) {
                                        form.style.transition = 'opacity 0.3s ease';
                                        form.style.opacity = '0';
                                        setTimeout(() => {
                                            if (form.parentNode) {
                                                form.remove();
                                            }
                                        }, 300);
                                    }
                                });
                            });
                            
                            // Hide the "Mark All as Read" button
                            button.style.transition = 'opacity 0.3s ease';
                            button.style.opacity = '0';
                            setTimeout(() => {
                                button.remove();
                            }, 300);
                        } else {
                            // Error - show message and restore button
                            const errorMsg = (data && data.message) ? data.message : 'Unknown error';
                            alert('Failed to mark all notifications as read: ' + errorMsg);
                            button.disabled = false;
                            button.innerHTML = originalText;
                        }
                    })
                    .catch(error => {
                        console.error('Error marking all notifications as read:', error);
                        alert('An error occurred while marking all notifications as read. Please try again.');
                        button.disabled = false;
                        button.innerHTML = originalText;
                    });
                });
            }
            
            // Debug logging
            console.log('Notification forms initialized:', document.querySelectorAll('.notification-action-form').length);
            console.log('Delete forms:', document.querySelectorAll('.notification-delete-form').length);
            console.log('Mark read forms:', document.querySelectorAll('.notification-mark-read-form').length);
        });
    </script>
</body>
</html>

