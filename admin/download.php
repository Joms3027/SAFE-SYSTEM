<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireAdmin();

$database = Database::getInstance();
$uploader = new FileUploader();

$filePath = $_GET['file'] ?? '';
$fileName = $_GET['name'] ?? '';

if (empty($filePath)) {
    http_response_code(404);
    die('File not found.');
}

// Security check - ensure file is in uploads directory
$fullPath = UPLOAD_PATH . $filePath;
if (!file_exists($fullPath) || !str_starts_with(realpath($fullPath), realpath(UPLOAD_PATH))) {
    http_response_code(404);
    die('File not found.');
}

// Log download action
logAction('FILE_DOWNLOAD', "Downloaded file: $filePath");

// Download the file
$uploader->downloadFile($filePath, $fileName);
?>






