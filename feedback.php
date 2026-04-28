<?php
/**
 * Legacy / public URL: send employees to the in-portal feedback form.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$basePath = getBasePath();
if (isLoggedIn() && (isFaculty() || isStaff())) {
    redirect(clean_url($basePath . '/faculty/feedback.php', $basePath));
}
redirect(clean_url($basePath . '/login.php', $basePath));
