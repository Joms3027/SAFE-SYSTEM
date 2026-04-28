<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$uploader = new FileUploader();

$submissionId = $_GET['id'] ?? null;

if (!$submissionId) {
    echo json_encode(['success' => false, 'message' => 'Submission ID is required']);
    exit;
}

$stmt = $db->prepare("
    SELECT 
        fs.*,
        u.first_name,
        u.last_name,
        u.email,
        r.title as requirement_title,
        r.description as requirement_description,
        r.deadline,
        reviewer.first_name as reviewer_first_name,
        reviewer.last_name as reviewer_last_name
    FROM faculty_submissions fs
    JOIN users u ON fs.faculty_id = u.id
    JOIN requirements r ON fs.requirement_id = r.id
    LEFT JOIN users reviewer ON fs.reviewed_by = reviewer.id
    WHERE fs.id = ?
");

$stmt->execute([$submissionId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    echo json_encode(['success' => false, 'message' => 'Submission not found']);
    exit;
}

// Format dates and file size
$submission['submitted_at_formatted'] = formatDate($submission['submitted_at'], 'M j, Y g:i A');
$submission['deadline_formatted'] = $submission['deadline'] ? formatDate($submission['deadline'], 'M j, Y') : null;
$submission['reviewed_at_formatted'] = $submission['reviewed_at'] ? formatDate($submission['reviewed_at'], 'M j, Y g:i A') : null;
$submission['file_size_formatted'] = $uploader->formatFileSize($submission['file_size']);

// Add status class for badge styling
$submission['status_class'] = 
    $submission['status'] === 'approved' ? 'success' : 
    ($submission['status'] === 'rejected' ? 'danger' : 'warning');

// Get previous versions of this submission
$previousVersions = [];
$versionStmt = $db->prepare("WITH RECURSIVE submission_history AS (
    SELECT * FROM faculty_submissions WHERE id = ?
    UNION ALL
    SELECT fs.* FROM faculty_submissions fs
    INNER JOIN submission_history sh ON fs.id = sh.previous_submission_id
)
SELECT sh.*, u.first_name as reviewer_first_name, u.last_name as reviewer_last_name, u.email as reviewer_email
FROM submission_history sh
LEFT JOIN users u ON sh.reviewed_by = u.id
WHERE sh.id != ?
ORDER BY sh.version DESC");
$versionStmt->execute([$submissionId, $submissionId]);
$previousVersions = $versionStmt->fetchAll(PDO::FETCH_ASSOC);

// Format previous versions
foreach ($previousVersions as &$version) {
    $version['submitted_at_formatted'] = formatDate($version['submitted_at'], 'M j, Y g:i A');
    $version['reviewed_at_formatted'] = $version['reviewed_at'] ? formatDate($version['reviewed_at'], 'M j, Y g:i A') : null;
    $version['file_size_formatted'] = $uploader->formatFileSize($version['file_size']);
}

echo json_encode([
    'success' => true,
    'submission' => $submission,
    'previousVersions' => $previousVersions
]);