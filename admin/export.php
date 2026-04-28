<?php
/**
 * Export Handler
 * Handles data export requests
 */

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/export_manager.php';

requireAdmin();

$exportType = $_GET['type'] ?? '';
$exportManager = getExportManager();

// Log the export action
logAction('EXPORT', "Exported {$exportType}");

// Get filters from query string
$filters = [];
if (isset($_GET['department'])) $filters['department'] = $_GET['department'];
if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
if (isset($_GET['verified'])) $filters['verified'] = $_GET['verified'];
if (isset($_GET['requirement_id'])) $filters['requirement_id'] = $_GET['requirement_id'];
if (isset($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
if (isset($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
if (isset($_GET['action'])) $filters['action'] = $_GET['action'];
if (isset($_GET['user_id'])) $filters['user_id'] = $_GET['user_id'];

switch ($exportType) {
    case 'faculty_list':
        $exportManager->exportFacultyList($filters);
        break;
        
    case 'submissions_report':
        $exportManager->exportSubmissionsReport($filters);
        break;
        
    case 'pds_report':
        $exportManager->exportPDSReport($filters);
        break;
        
    case 'activity_logs':
        $exportManager->exportActivityLogs($filters);
        break;
        
    case 'requirements_summary':
        $exportManager->exportRequirementsSummary();
        break;
        
    default:
        $_SESSION['error'] = 'Invalid export type';
        // Redirect back to the page that called this export, or dashboard as fallback
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (!empty($referer) && strpos($referer, 'export.php') === false) {
            // Use referer if it exists and is not this export page
            header('Location: ' . $referer);
        } else {
            // Fallback to dashboard
            header('Location: dashboard.php');
        }
        exit();
        break;
}
?>
