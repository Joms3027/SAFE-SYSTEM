<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

requireAdmin();
header('Location: dashboard.php');
exit();
?>