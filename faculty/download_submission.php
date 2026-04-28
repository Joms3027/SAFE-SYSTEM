<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();
$uploader = new FileUploader();

$filePath = $_GET['file'] ?? '';
$fileName = $_GET['name'] ?? '';

if (empty($filePath)) {
    http_response_code(404);
    die('File not found.');
}

// Verify the submission belongs to the current faculty
$stmt = $db->prepare("SELECT * FROM faculty_submissions WHERE file_path = ? AND faculty_id = ? LIMIT 1");
$stmt->execute([$filePath, $_SESSION['user_id']]);
$submission = $stmt->fetch();

if (!$submission) {
    http_response_code(403);
    die('Access denied or file not found.');
}

// Security check - ensure file exists within upload path
$fullPath = UPLOAD_PATH . $filePath;
if (!file_exists($fullPath) || !str_starts_with(realpath($fullPath), realpath(UPLOAD_PATH))) {
    http_response_code(404);
    die('File not found.');
}

// Log download action
logAction('FILE_DOWNLOAD', "Downloaded file: {$filePath}");

// Use provided name if available, otherwise use stored original_filename
$downloadName = $fileName ?: $submission['original_filename'];

$uploader->downloadFile($filePath, $downloadName);

?>