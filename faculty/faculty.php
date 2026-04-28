<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// CRITICAL: Use absolute paths for all redirects to fix PWA navigation on mobile
$basePath = getBasePath();

// Check if user is logged in before redirecting
if (!isLoggedIn()) {
    redirect($basePath . '/login.php');
}

// Check if user has faculty/staff access
if (!isFaculty() && !isStaff()) {
    $_SESSION['error'] = "Access denied. Faculty or Staff privileges required.";
    redirect($basePath . '/login.php');
}

// User is authenticated and has proper role, redirect to dashboard
// Use absolute path to prevent PWA navigation issues on mobile
header('Location: ' . $basePath . '/faculty/dashboard.php');
exit();
?>
