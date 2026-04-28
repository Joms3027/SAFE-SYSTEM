<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireFaculty();

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

// Get file extension
$extension = strtolower(pathinfo($fileName ?: $filePath, PATHINFO_EXTENSION));

// Determine MIME type based on extension
$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];

$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

// For PDFs and images, display inline
if (in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'gif'])) {
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . htmlspecialchars($fileName ?: basename($filePath)) . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Log view action
    logAction('FILE_VIEW', "Viewed file: $filePath");
    
    // Output file
    readfile($fullPath);
    exit;
} else {
    // For other file types, try to display if browser supports it, otherwise download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . htmlspecialchars($fileName ?: basename($filePath)) . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Log view action
    logAction('FILE_VIEW', "Viewed file: $filePath");
    
    // Output file
    readfile($fullPath);
    exit;
}
?>

