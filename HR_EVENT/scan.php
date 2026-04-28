<?php
/**
 * Legacy: Employees no longer scan an event QR; they present their SAFE QR at the event.
 * Redirect to Event Check-in landing.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$basePath = getBasePath();
header('Location: ' . $basePath . '/HR_EVENT/index.php');
exit;
