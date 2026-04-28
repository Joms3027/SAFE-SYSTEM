<?php
/**
 * File Preview Handler
 * Provides inline preview for documents
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';

requireAuth();

$fileType = $_GET['type'] ?? 'submission';
$fileId = $_GET['id'] ?? null;

if (!$fileId) {
    http_response_code(400);
    die('File ID required');
}

$database = Database::getInstance();
$db = $database->getConnection();

$filePath = null;
$fileName = null;
$mimeType = null;

// Get file information based on type
switch ($fileType) {
    case 'submission':
        $stmt = $db->prepare("
            SELECT fs.file_path, fs.original_filename, fs.faculty_id 
            FROM faculty_submissions fs 
            WHERE fs.id = ?
        ");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if ($file) {
            // Check access rights
            if (!isAdmin() && $_SESSION['user_id'] != $file['faculty_id']) {
                http_response_code(403);
                die('Access denied');
            }
            
            $filePath = '../uploads/submissions/' . $file['file_path'];
            $fileName = $file['original_filename'];
        }
        break;
        
    case 'pds':
        $stmt = $db->prepare("
            SELECT pds.*, u.id as faculty_id
            FROM faculty_pds pds
            JOIN users u ON pds.faculty_id = u.id
            WHERE pds.id = ?
        ");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if ($file) {
            // Check access rights
            if (!isAdmin() && $_SESSION['user_id'] != $file['faculty_id']) {
                http_response_code(403);
                die('Access denied');
            }
            
            // PDS files are stored as JSON, not files
            http_response_code(400);
            die('PDS preview not supported via this endpoint');
        }
        break;
        
    case 'requirement':
        // For requirement attachments
        $stmt = $db->prepare("
            SELECT file_path, original_filename 
            FROM requirement_attachments 
            WHERE id = ?
        ");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if ($file) {
            $filePath = '../uploads/requirements/' . $file['file_path'];
            $fileName = $file['original_filename'];
        }
        break;
        
    default:
        http_response_code(400);
        die('Invalid file type');
}

if (!$file || !$filePath || !file_exists($filePath)) {
    http_response_code(404);
    die('File not found');
}

// Determine mime type
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
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

// For direct preview in browser (PDF and images)
if (in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'gif'])) {
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
} else {
    // For other files, provide download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
}

exit;
?>
