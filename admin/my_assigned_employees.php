<?php
/**
 * Redirect to faculty/my_assigned_employees.php (employee side).
 * Kept for backwards compatibility with bookmarks/links.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';

requireAuth();

$basePath = getBasePath();
redirect(clean_url($basePath . '/faculty/my_assigned_employees.php', $basePath));
