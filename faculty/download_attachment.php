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

if (empty($filePath)) {
    http_response_code(404);
    die('File not found.');
}

// Look up the attachment to ensure the current faculty owns it
$stmt = $db->prepare("SELECT * FROM requirement_attachments WHERE file_path = ? AND faculty_id = ? LIMIT 1");
$stmt->execute([$filePath, $_SESSION['user_id']]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(403);
    die('Access denied or file not found.');
}

// Security check - ensure file exists within upload path
$fullPath = UPLOAD_PATH . $filePath;
if (!file_exists($fullPath) || !str_starts_with(realpath($fullPath), realpath(UPLOAD_PATH))) {
    http_response_code(404);
    die('File not found.');
}

logAction('ATTACHMENT_DOWNLOAD', "Downloaded attachment: {$filePath}");

$uploader->downloadFile($filePath, $attachment['original_filename']);

?>
