<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/notifications.php';
require_once '../includes/auth.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$notificationManager = getNotificationManager();
$userId = $_SESSION['user_id'];

// Handle actions (fallback for non-JS users, but AJAX is preferred)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read']) && !isset($_POST['ajax'])) {
        // Only handle mark_all_read via POST if not AJAX
        if ($notificationManager->markAllAsRead($userId)) {
            $_SESSION['success'] = 'All notifications marked as read';
        } else {
            $_SESSION['error'] = 'Failed to mark all notifications as read';
        }
        header('Location: notifications.php');
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
    <title>Notifications - Admin Console</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = '';
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $pathSegments = array_filter(explode('/', $scriptPath));
        if (count($pathSegments) > 0) {
            $basePath = '/' . reset($pathSegments);
        }
    }
    if (empty($basePath) && isset($_SERVER['REQUEST_URI'])) {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($requestUri && $requestUri !== '/') {
            $uriSegments = array_filter(explode('/', $requestUri));
            if (count($uriSegments) > 0) {
                $basePath = '/' . reset($uriSegments);
            }
        }
    }
    if (empty($basePath)) {
        $basePath = '/FP';
    }
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/admin-portal.css', true); ?>" rel="stylesheet">
    <style>
        * {
            --primary: #003366;
            --primary-light: #005599;
            --accent: #0284c7;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --border: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }

        body.layout-admin {
            background: var(--gray-50);
        }

        .admin-notifications-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 1.5rem 1rem;
        }

        /* Compact Header */
        .admin-notifications-header {
            background: white;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .admin-notifications-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .admin-notifications-header h2 i {
            color: var(--accent);
            font-size: 1.25rem;
        }

        .admin-notifications-header p {
            margin: 0.25rem 0 0 0;
            color: var(--gray-500);
            font-size: 0.875rem;
        }

        .admin-notifications-actions .btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 6px;
        }

        /* Minimal Filter Tabs */
        .admin-filter-tabs {
            background: white;
            border-radius: 8px;
            padding: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            display: inline-flex;
            gap: 0.25rem;
        }

        .admin-filter-tabs .nav-link {
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.15s ease;
            color: var(--gray-600);
            border: none;
        }


        .admin-filter-tabs .nav-link.active {
            background: var(--primary);
            color: white;
        }

        .admin-filter-tabs .nav-link .badge {
            font-size: 0.75rem;
            padding: 0.125rem 0.5rem;
            margin-left: 0.375rem;
        }

        /* Compact Grid */
        .admin-notifications-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .admin-notifications-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Minimalist Cards */
        .admin-notification-card {
            background: white;
            border-radius: 8px;
            border: 1px solid var(--border);
            padding: 1rem;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: relative;
        }


        .admin-notification-card.unread {
            border-left: 3px solid var(--accent);
            background: linear-gradient(to right, rgba(2, 132, 199, 0.03) 0%, white 3%);
        }

        .admin-notification-card.priority-high {
            border-left: 3px solid #dc2626;
        }

        .admin-notification-header-row {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        /* Compact Icons */
        .admin-notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .admin-notification-icon.submission_status,
        .admin-notification-icon.submission {
            background: #eff6ff;
            color: var(--accent);
        }

        .admin-notification-icon.pardon_request {
            background: #fffbeb;
            color: #d97706;
        }

        .admin-notification-icon.pds_status {
            background: #f0f4ff;
            color: #6366f1;
        }

        .admin-notification-icon.new_requirement {
            background: #fffbeb;
            color: #d97706;
        }

        .admin-notification-icon.deadline_reminder {
            background: #fef2f2;
            color: #dc2626;
        }

        .admin-notification-icon.announcement {
            background: #f5f3ff;
            color: #7c3aed;
        }

        .admin-notification-icon.system {
            background: var(--gray-100);
            color: var(--gray-500);
        }

        .admin-notification-content {
            flex: 1;
            min-width: 0;
        }

        /* Typography */
        .admin-notification-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            line-height: 1.4;
        }

        .admin-notification-title .badge {
            font-size: 0.6875rem;
            padding: 0.1875rem 0.5rem;
            font-weight: 500;
            border-radius: 4px;
        }

        .admin-notification-message {
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }

        .admin-notification-meta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.75rem;
            color: var(--gray-400);
            margin-bottom: 0.5rem;
        }

        .admin-notification-meta i {
            font-size: 0.6875rem;
        }

        /* Compact Actions */
        .admin-notification-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.25rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--gray-100);
        }

        .admin-notification-actions .btn {
            flex: 1;
            min-width: 80px;
            font-size: 0.8125rem;
            padding: 0.4375rem 0.875rem;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .admin-notification-actions .btn:hover {
            transform: translateY(-1px);
        }

        .admin-notification-actions .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .admin-notification-actions .btn-success {
            background: #10b981;
            border-color: #10b981;
        }

        .admin-notification-actions .btn-danger {
            background: #ef4444;
            border-color: #ef4444;
        }

        /* Empty State */
        .admin-empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: 8px;
            border: 1px dashed var(--gray-300);
        }

        .admin-empty-state i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 0.75rem;
        }

        .admin-empty-state h4 {
            color: var(--gray-600);
            margin-bottom: 0.375rem;
            font-size: 1rem;
            font-weight: 500;
        }

        .admin-empty-state p {
            color: var(--gray-400);
            margin: 0;
            font-size: 0.875rem;
        }

        /* Mobile Optimizations */
        @media (max-width: 767px) {
            .admin-notifications-container {
                padding: 1rem 0.75rem;
            }

            .admin-notifications-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
            }

            .admin-notifications-header h2 {
                font-size: 1.25rem;
            }

            .admin-notifications-actions {
                width: 100%;
            }

            .admin-notifications-actions .btn {
                flex: 1;
            }

            .admin-notification-card {
                padding: 0.875rem;
            }

            .admin-notification-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }

            .admin-notification-actions {
                flex-direction: column;
            }

            .admin-notification-actions .btn {
                width: 100%;
            }

            .admin-filter-tabs {
                width: 100%;
            }

            .admin-filter-tabs .nav-link {
                flex: 1;
                text-align: center;
            }
        }

        /* Smooth animations */
        @media (prefers-reduced-motion: no-preference) {
            .admin-notification-card {
                animation: fadeIn 0.3s ease-out;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(4px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        }

        /* Custom Confirmation Modal */
        .custom-confirm-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeInOverlay 0.2s ease-out;
        }

        .custom-confirm-overlay.show {
            display: flex;
        }

        @keyframes fadeInOverlay {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .custom-confirm-modal {
            background: white;
            border-radius: 12px;
            padding: 0;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease-out;
            overflow: hidden;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .custom-confirm-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .custom-confirm-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .custom-confirm-icon.warning {
            background: #fef2f2;
            color: #dc2626;
        }

        .custom-confirm-icon.info {
            background: #eff6ff;
            color: var(--accent);
        }

        .custom-confirm-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .custom-confirm-body {
            padding: 1.5rem;
            color: var(--gray-600);
            font-size: 0.9375rem;
            line-height: 1.6;
        }

        .custom-confirm-actions {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .custom-confirm-btn {
            padding: 0.625rem 1.25rem !important;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 !important;
            box-sizing: border-box;
        }
        
        /* Block Bootstrap and any other padding overrides */
        .custom-confirm-btn,
        .custom-confirm-btn.btn,
        .custom-confirm-btn[class*="btn"],
        .custom-confirm-actions .custom-confirm-btn,
        .custom-confirm-actions button.custom-confirm-btn {
            padding: 0.625rem 1.25rem !important;
            margin: 0 !important;
        }
        
        /* Override any inline styles that might add padding */
        .custom-confirm-btn[style*="padding"] {
            padding: 0.625rem 1.25rem !important;
        }

        .custom-confirm-btn:hover {
            transform: translateY(-1px);
        }

        .custom-confirm-btn:active {
            transform: translateY(0);
        }

        .custom-confirm-btn-cancel {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .custom-confirm-btn-cancel:hover {
            background: var(--gray-200);
        }

        .custom-confirm-btn-confirm {
            background: #dc2626;
            color: white;
        }

        .custom-confirm-btn-confirm:hover {
            background: #b91c1c;
        }

        .custom-confirm-btn-primary {
            background: var(--primary);
            color: white;
        }

        .custom-confirm-btn-primary:hover {
            background: var(--primary-light);
        }

        /* Toast Notification */
        .custom-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border-left: 4px solid;
            z-index: 10000;
            display: none;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
            max-width: 400px;
            animation: slideInRight 0.3s ease-out;
        }

        .custom-toast.show {
            display: flex;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .custom-toast.success {
            border-left-color: #10b981;
        }

        .custom-toast.error {
            border-left-color: #dc2626;
        }

        .custom-toast.info {
            border-left-color: var(--accent);
        }

        .custom-toast-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .custom-toast.success .custom-toast-icon {
            background: #d1fae5;
            color: #10b981;
        }

        .custom-toast.error .custom-toast-icon {
            background: #fee2e2;
            color: #dc2626;
        }

        .custom-toast.info .custom-toast-icon {
            background: #dbeafe;
            color: var(--accent);
        }

        .custom-toast-content {
            flex: 1;
        }

        .custom-toast-title {
            font-weight: 600;
            font-size: 0.9375rem;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }

        .custom-toast-message {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .custom-toast-close {
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1rem;
            line-height: 1;
            transition: color 0.2s;
        }

        .custom-toast-close:hover {
            color: var(--gray-600);
        }

        @media (max-width: 767px) {
            .custom-confirm-modal {
                max-width: 100%;
                margin: 1rem;
            }

            .custom-toast {
                right: 10px;
                left: 10px;
                min-width: auto;
                max-width: 100%;
            }
        }
    </style>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="admin-notifications-container">
                    <!-- Compact Header -->
                    <div class="admin-notifications-header">
                        <div>
                            <h2>
                                <i class="fas fa-bell"></i>
                                Notifications
                            </h2>
                            <p>Stay updated on requirement changes, deadlines, and important announcements.</p>
                        </div>
                        <?php if ($unreadCount > 0): ?>
                            <div class="admin-notifications-actions">
                                <button type="button" id="markAllReadBtn" class="btn btn-primary btn-sm">
                                    <i class="fas fa-check-double me-1"></i>Mark All Read
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php displayMessage(); ?>

                    <!-- Filter Tabs -->
                    <div class="admin-filter-tabs">
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
                    </div>

                    <!-- Notifications Grid -->
                    <?php if (empty($notifications)): ?>
                        <div class="admin-empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h4>No notifications</h4>
                            <p>You're all caught up!</p>
                        </div>
                    <?php else: ?>
                        <div class="admin-notifications-grid">
                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                    $cardClasses = ['admin-notification-card'];
                                    if ($notif['is_read'] == 0) {
                                        $cardClasses[] = 'unread';
                                    }
                                    if (in_array(($notif['priority'] ?? 'normal'), ['high', 'urgent'], true)) {
                                        $cardClasses[] = 'priority-high';
                                    }
                                    
                                    $icons = [
                                        'submission_status' => 'file-check',
                                        'submission' => 'file-upload',
                                        'pds_status' => 'user-check',
                                        'pardon_request' => 'clock',
                                        'new_requirement' => 'tasks',
                                        'deadline_reminder' => 'exclamation-triangle',
                                        'announcement' => 'bullhorn',
                                        'system' => 'cog'
                                    ];
                                    $icon = $icons[$notif['type']] ?? 'info-circle';
                                ?>
                                <div class="<?php echo implode(' ', $cardClasses); ?>" data-notification-id="<?php echo (int)$notif['id']; ?>">
                                    <div class="admin-notification-header-row">
                                        <div class="admin-notification-icon <?php echo htmlspecialchars($notif['type']); ?>">
                                            <i class="fas fa-<?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="admin-notification-content">
                                            <div class="admin-notification-title">
                                                    <?php echo htmlspecialchars($notif['title']); ?>
                                                    <?php if ($notif['is_read'] == 0): ?>
                                                        <span class="badge bg-primary" style="font-size: 0.7rem;">New</span>
                                                    <?php endif; ?>
                                                    <?php if (in_array(($notif['priority'] ?? 'normal'), ['high', 'urgent'], true)): ?>
                                                        <span class="badge bg-danger" style="font-size: 0.7rem;">High Priority</span>
                                                    <?php endif; ?>
                                                </div>
                                            <div class="admin-notification-message">
                                                <?php echo nl2br(htmlspecialchars($notif['message'])); ?>
                                            </div>
                                            <div class="admin-notification-meta">
                                                <span>
                                                    <i class="far fa-clock me-1"></i><?php echo timeAgo($notif['created_at']); ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-tag me-1"></i><?php echo ucfirst(str_replace('_', ' ', $notif['type'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="admin-notification-actions">
                                        <?php if (!empty($notif['link_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($notif['link_url']); ?>" 
                                               class="btn btn-primary btn-sm"
                                               title="View details">
                                                <i class="fas fa-arrow-right"></i>
                                                <span>View</span>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($notif['is_read'] == 0): ?>
                                            <button type="button" 
                                                    class="btn btn-success btn-sm mark-read-btn" 
                                                    data-notification-id="<?php echo (int)$notif['id']; ?>"
                                                    title="Mark as read">
                                                <i class="fas fa-check"></i>
                                                <span>Read</span>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" 
                                                class="btn btn-danger btn-sm remove-btn" 
                                                data-notification-id="<?php echo (int)$notif['id']; ?>"
                                                title="Remove">
                                            <i class="fas fa-times"></i>
                                            <span>Remove</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Custom Confirmation Modal -->
    <div id="customConfirmOverlay" class="custom-confirm-overlay">
        <div class="custom-confirm-modal">
            <div class="custom-confirm-header">
                <div class="custom-confirm-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="custom-confirm-title">Confirm Action</h3>
            </div>
            <div class="custom-confirm-body">
                Are you sure you want to proceed?
            </div>
            <div class="custom-confirm-actions">
                <button class="custom-confirm-btn custom-confirm-btn-cancel" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;">Cancel</button>
                <button class="custom-confirm-btn custom-confirm-btn-confirm" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;">Confirm</button>
            </div>
        </div>
    </div>
    
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        (function() {
            // Get API base URL
            function getApiUrl() {
                // Use relative path for better compatibility
                return '../includes/notifications_api.php';
            }
            
            // Mark notification as read
            function markAsRead(notificationId, button) {
                const originalHTML = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>...</span>';
                
                fetch(getApiUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: `action=mark_read&notification_id=${notificationId}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP error ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        // Find the notification card
                        const card = button.closest('.admin-notification-card');
                        if (card) {
                            // Remove unread styling
                            card.classList.remove('unread');
                            
                            // Remove "New" badge
                            const newBadge = card.querySelector('.badge.bg-primary');
                            if (newBadge && newBadge.textContent.trim() === 'New') {
                                newBadge.remove();
                            }
                            
                            // Remove the mark read button
                            button.style.transition = 'opacity 0.3s ease';
                            button.style.opacity = '0';
                            setTimeout(() => button.remove(), 300);
                            
                            // Update unread count
                            updateUnreadCount();
                            showToast('success', 'Marked as Read', 'Notification has been marked as read.');
                        }
                    } else {
                        showToast('error', 'Failed to mark as read', (data && data.message) ? data.message : 'Unknown error');
                        button.disabled = false;
                        button.innerHTML = originalHTML;
                    }
                })
                .catch(error => {
                    console.error('Error marking as read:', error);
                    showToast('error', 'Error', 'An error occurred. Please try again.');
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                });
            }
            
            // Remove notification
            function removeNotification(notificationId, button) {
                showConfirm(
                    'Remove Notification',
                    'Are you sure you want to remove this notification? This action cannot be undone.',
                    'warning',
                    function() {
                        const originalHTML = button.innerHTML;
                        button.disabled = true;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>...</span>';
                        
                        fetch(getApiUrl(), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'Accept': 'application/json'
                            },
                            credentials: 'same-origin',
                            body: `action=delete&notification_id=${notificationId}`
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('HTTP error ' + response.status);
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data && data.success) {
                                // Find the notification card
                                const card = button.closest('.admin-notification-card');
                                if (card) {
                                    // Animate removal
                                    card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                                    card.style.opacity = '0';
                                    card.style.transform = 'translateX(-20px)';
                                    
                                    setTimeout(() => {
                                        card.remove();
                                        
                                        // Check if no notifications left
                                        const grid = document.querySelector('.admin-notifications-grid');
                                        if (grid && grid.children.length === 0) {
                                            // Show empty state
                                            const emptyState = document.createElement('div');
                                            emptyState.className = 'admin-empty-state';
                                            emptyState.innerHTML = `
                                                <i class="fas fa-bell-slash"></i>
                                                <h4>No notifications</h4>
                                                <p>You're all caught up!</p>
                                            `;
                                            grid.parentElement.appendChild(emptyState);
                                        }
                                        
                                        // Update counts
                                        updateUnreadCount();
                                        updateFilterCounts();
                                        
                                        showToast('success', 'Notification Removed', 'The notification has been removed successfully.');
                                    }, 300);
                                }
                            } else {
                                showToast('error', 'Failed to Remove', (data && data.message) ? data.message : 'Unknown error');
                                button.disabled = false;
                                button.innerHTML = originalHTML;
                            }
                        })
                        .catch(error => {
                            console.error('Error removing notification:', error);
                            showToast('error', 'Error', 'An error occurred. Please try again.');
                            button.disabled = false;
                            button.innerHTML = originalHTML;
                        });
                    }
                );
            }
            
            // Mark all as read
            function markAllAsRead(button) {
                const originalHTML = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>...</span>';
                
                fetch(getApiUrl(), {
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
                        // Remove unread styling from all cards
                        document.querySelectorAll('.admin-notification-card.unread').forEach(card => {
                            card.classList.remove('unread');
                            
                            // Remove "New" badges
                            const newBadges = card.querySelectorAll('.badge.bg-primary');
                            newBadges.forEach(badge => {
                                if (badge.textContent.trim() === 'New') {
                                    badge.remove();
                                }
                            });
                            
                            // Remove mark read buttons
                            const markReadBtns = card.querySelectorAll('.mark-read-btn');
                            markReadBtns.forEach(btn => {
                                btn.style.transition = 'opacity 0.3s ease';
                                btn.style.opacity = '0';
                                setTimeout(() => btn.remove(), 300);
                            });
                        });
                        
                        // Hide the "Mark All Read" button
                        button.style.transition = 'opacity 0.3s ease';
                        button.style.opacity = '0';
                        setTimeout(() => button.remove(), 300);
                        
                        // Update counts
                        updateUnreadCount();
                        updateFilterCounts();
                        showToast('success', 'All Marked as Read', 'All notifications have been marked as read.');
                    } else {
                        showToast('error', 'Failed to Mark All as Read', (data && data.message) ? data.message : 'Unknown error');
                        button.disabled = false;
                        button.innerHTML = originalHTML;
                    }
                })
                .catch(error => {
                    console.error('Error marking all as read:', error);
                    showToast('error', 'Error', 'An error occurred. Please try again.');
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                });
            }
            
            // Show confirmation modal
            function showConfirm(title, message, type, onConfirm) {
                const overlay = document.getElementById('customConfirmOverlay');
                const modal = overlay.querySelector('.custom-confirm-modal');
                const iconEl = modal.querySelector('.custom-confirm-icon');
                const titleEl = modal.querySelector('.custom-confirm-title');
                const bodyEl = modal.querySelector('.custom-confirm-body');
                const confirmBtn = modal.querySelector('.custom-confirm-btn-confirm');
                const cancelBtn = modal.querySelector('.custom-confirm-btn-cancel');
                
                // Set content
                titleEl.textContent = title;
                bodyEl.textContent = message;
                
                // Set icon
                iconEl.className = 'custom-confirm-icon ' + (type || 'warning');
                if (type === 'warning') {
                    iconEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                } else {
                    iconEl.innerHTML = '<i class="fas fa-info-circle"></i>';
                }
                
                // Show overlay
                overlay.classList.add('show');
                
                // Handle confirm
                const handleConfirm = function() {
                    overlay.classList.remove('show');
                    if (onConfirm) onConfirm();
                    confirmBtn.removeEventListener('click', handleConfirm);
                    cancelBtn.removeEventListener('click', handleCancel);
                    overlay.removeEventListener('click', handleOverlayClick);
                };
                
                // Handle cancel
                const handleCancel = function() {
                    overlay.classList.remove('show');
                    confirmBtn.removeEventListener('click', handleConfirm);
                    cancelBtn.removeEventListener('click', handleCancel);
                    overlay.removeEventListener('click', handleOverlayClick);
                };
                
                // Handle overlay click
                const handleOverlayClick = function(e) {
                    if (e.target === overlay) {
                        handleCancel();
                    }
                };
                
                confirmBtn.addEventListener('click', handleConfirm);
                cancelBtn.addEventListener('click', handleCancel);
                overlay.addEventListener('click', handleOverlayClick);
            }
            
            // Show toast notification
            function showToast(type, title, message) {
                // Remove existing toast if any
                const existingToast = document.querySelector('.custom-toast');
                if (existingToast) {
                    existingToast.remove();
                }
                
                const toast = document.createElement('div');
                toast.className = `custom-toast ${type} show`;
                
                const iconMap = {
                    success: '<i class="fas fa-check-circle"></i>',
                    error: '<i class="fas fa-exclamation-circle"></i>',
                    info: '<i class="fas fa-info-circle"></i>'
                };
                
                toast.innerHTML = `
                    <div class="custom-toast-icon">${iconMap[type] || iconMap.info}</div>
                    <div class="custom-toast-content">
                        <div class="custom-toast-title">${title}</div>
                        <div class="custom-toast-message">${message}</div>
                    </div>
                    <button class="custom-toast-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                document.body.appendChild(toast);
                
                // Auto remove after 4 seconds
                setTimeout(() => {
                    toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateX(100%)';
                    setTimeout(() => toast.remove(), 300);
                }, 4000);
            }
            
            // Update unread count
            function updateUnreadCount() {
                fetch(getApiUrl() + '?action=get_count', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP error ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        const count = data.count || 0;
                        // Update badge in notification bell (if exists)
                        const badge = document.querySelector('#notificationBadge');
                        if (badge) {
                            if (count > 0) {
                                badge.textContent = count > 99 ? '99+' : count;
                                badge.style.display = 'block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                        
                        // Update filter tab badge
                        const unreadBadge = document.querySelector('.admin-filter-tabs .nav-link[href*="unread"] .badge');
                        if (unreadBadge) {
                            unreadBadge.textContent = count;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error updating unread count:', error);
                });
            }
            
            // Update filter counts
            function updateFilterCounts() {
                const allCount = document.querySelectorAll('.admin-notification-card').length;
                const allBadge = document.querySelector('.admin-filter-tabs .nav-link[href*="all"] .badge');
                if (allBadge) {
                    allBadge.textContent = allCount;
                }
            }
            
            // Initialize event listeners
            document.addEventListener('DOMContentLoaded', function() {
                // Mark as read buttons
                document.querySelectorAll('.mark-read-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const notificationId = this.getAttribute('data-notification-id');
                        markAsRead(notificationId, this);
                    });
                });
                
                // Remove buttons
                document.querySelectorAll('.remove-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const notificationId = this.getAttribute('data-notification-id');
                        removeNotification(notificationId, this);
                    });
                });
                
                // Mark all as read button
                const markAllBtn = document.getElementById('markAllReadBtn');
                if (markAllBtn) {
                    markAllBtn.addEventListener('click', function() {
                        markAllAsRead(this);
                    });
                }
            });
        })();
    </script>
</body>
</html>
