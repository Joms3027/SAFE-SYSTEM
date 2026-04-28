<?php
/**
 * Admin Access Information - RESTRICTED
 * This page is disabled for security. Default credentials have been removed.
 * 
 * To access the admin panel, use the login page at login.php
 * If you need to reset admin credentials, use the database directly or
 * contact the system administrator.
 */

// Redirect to login page - this file should not be publicly accessible
header('HTTP/1.1 403 Forbidden');
header('Location: login.php');
exit;
