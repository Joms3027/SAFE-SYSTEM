<?php
/**
 * Helper function to include the correct navigation components based on user role
 * Includes both header and sidebar components
 */
function include_navigation() {
    if (!defined('NAV_INCLUDED')) {
        // Get user role from session
        $role = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';
        // Load database helper if needed to fetch notifications
        if (file_exists(dirname(__FILE__) . '/database.php')) {
            require_once dirname(__FILE__) . '/database.php';
        }

        // Prepare notifications variable for header (used by header.php)
        $notifications = [];
        try {
            if (($role === 'admin' || $role === 'super_admin' || $role === 'staff') && class_exists('Database')) {
                $database = Database::getInstance();
                $db = $database->getConnection();
                
                // Check if is_hidden column exists
                $checkColumn = $db->query("SHOW COLUMNS FROM notifications LIKE 'is_hidden'");
                $columnExists = $checkColumn->rowCount() > 0;
                
                if ($columnExists) {
                    $stmt = $db->prepare("SELECT id, type, message, created_at FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0 AND is_hidden = 0 ORDER BY created_at DESC LIMIT 10");
                } else {
                    $stmt = $db->prepare("SELECT id, type, message, created_at FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
                }
                $stmt->execute([$_SESSION['user_id'] ?? 0]);
                $notifications = $stmt->fetchAll();
            }
        } catch (Exception $e) {
            // If notifications fail, ignore — header will show nothing
            error_log('Failed to load notifications: ' . $e->getMessage());
            $notifications = [];
        }

        // Include common header (header.php uses $notifications if present)
        include_once dirname(__FILE__) . '/header.php';
        
        // Include role-specific sidebar
        if ($role === 'admin' || $role === 'super_admin') {
            include_once dirname(__FILE__) . '/../admin/admin_sidebar.php';
        } elseif ($role === 'faculty' || $role === 'staff') {
            include_once dirname(__FILE__) . '/../faculty/faculty_sidebar.php';
        }
        
        define('NAV_INCLUDED', true);
    }
}
?>