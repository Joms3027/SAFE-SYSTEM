<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$uploader = new FileUploader();

$action = $_GET['action'] ?? 'list';
$submissionId = $_GET['id'] ?? null;
$requirementId = $_GET['requirement_id'] ?? null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'review';
    
    if ($action === 'review') {
        $submissionId = (int)$_POST['submission_id'];
        $status = $_POST['status'];
        $adminNotes = sanitizeInput($_POST['admin_notes']);
        
        // Get submission details for notification
        $stmt = $db->prepare("SELECT fs.faculty_id, r.title FROM faculty_submissions fs JOIN requirements r ON fs.requirement_id = r.id WHERE fs.id = ?");
        $stmt->execute([$submissionId]);
        $submissionInfo = $stmt->fetch();
        
        $stmt = $db->prepare("UPDATE faculty_submissions SET status = ?, admin_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        
        if ($stmt->execute([$status, $adminNotes, $_SESSION['user_id'], $submissionId])) {
            $_SESSION['success'] = 'Submission reviewed successfully!';
            logAction('SUBMISSION_REVIEWED', "Reviewed submission ID: $submissionId with status: $status");
            
            // Send notification to faculty
            require_once '../includes/notifications.php';
            require_once '../includes/mailer.php';
            $notificationManager = getNotificationManager();
            $mailer = new Mailer();
            
            // Send in-app notification
            $notificationManager->notifySubmissionStatus(
                $submissionInfo['faculty_id'],
                $submissionInfo['title'],
                $status,
                $submissionId
            );
            
            // Get faculty email
            $stmt = $db->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$submissionInfo['faculty_id']]);
            $faculty = $stmt->fetch();
            
            // Send email notification
            if ($faculty) {
                $mailer->sendSubmissionStatusNotification(
                    $faculty['email'],
                    $faculty['first_name'] . ' ' . $faculty['last_name'],
                    $submissionInfo['title'],
                    $status,
                    $adminNotes
                );
            }
        } else {
            $_SESSION['error'] = 'Failed to review submission.';
        }
        
        header('Location: submissions.php');
        exit();
    }
}

// Get submissions with filters
$whereClause = "1=1";
$params = [];

if ($requirementId) {
    $whereClause .= " AND fs.requirement_id = ?";
    $params[] = $requirementId;
}

$statusFilter = $_GET['status'] ?? '';
if ($statusFilter) {
    $whereClause .= " AND fs.status = ?";
    $params[] = $statusFilter;
}

$search = $_GET['search'] ?? '';
if ($search) {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR r.title LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Prepare count SQL: count distinct faculty+requirement latest versions so admin sees the latest submission per faculty per requirement
$countSql = "SELECT COUNT(*) FROM (
    SELECT fs2.faculty_id, fs2.requirement_id, MAX(fs2.version) as maxv
    FROM faculty_submissions fs2
    GROUP BY fs2.faculty_id, fs2.requirement_id
) latest
JOIN faculty_submissions fs ON fs.faculty_id = latest.faculty_id AND fs.requirement_id = latest.requirement_id AND fs.version = latest.maxv
JOIN users u ON fs.faculty_id = u.id
JOIN requirements r ON fs.requirement_id = r.id
LEFT JOIN users reviewer ON fs.reviewed_by = reviewer.id
WHERE $whereClause";

// Get pagination parameters (10 per page)
$p = getPaginationParams($db, $countSql, $params, 10);

$sql = "SELECT fs.*, u.first_name, u.last_name, u.email, r.title as requirement_title, r.deadline,
           reviewer.first_name as reviewer_first_name, reviewer.last_name as reviewer_last_name
    FROM (
        SELECT fs3.* FROM faculty_submissions fs3
        JOIN (
            SELECT faculty_id, requirement_id, MAX(version) as maxv
            FROM faculty_submissions
            GROUP BY faculty_id, requirement_id
        ) mv ON fs3.faculty_id = mv.faculty_id AND fs3.requirement_id = mv.requirement_id AND fs3.version = mv.maxv
    ) fs
    JOIN users u ON fs.faculty_id = u.id
    JOIN requirements r ON fs.requirement_id = r.id
    LEFT JOIN users reviewer ON fs.reviewed_by = reviewer.id
    WHERE $whereClause
    ORDER BY fs.submitted_at DESC
    LIMIT {$p['limit']} OFFSET {$p['offset']}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

// Get requirements for filter (optimized: only select needed columns)
$stmt = $db->prepare("SELECT id, title, deadline, is_active FROM requirements WHERE is_active = 1 ORDER BY title");
$stmt->execute();
$requirements = $stmt->fetchAll();

// Get specific submission for review
$submission = null;
$previousVersions = [];
if ($submissionId) {
    $stmt = $db->prepare("SELECT fs.*, u.first_name, u.last_name, u.email, r.title as requirement_title, r.description as requirement_description,
                         reviewer.first_name as reviewer_first_name, reviewer.last_name as reviewer_last_name
                         FROM faculty_submissions fs
                         JOIN users u ON fs.faculty_id = u.id
                         JOIN requirements r ON fs.requirement_id = r.id
                         LEFT JOIN users reviewer ON fs.reviewed_by = reviewer.id
                         WHERE fs.id = ?");
                         
    // Get previous versions of this submission
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
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('File Submissions', 'Review and manage faculty file submissions');
    ?>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'File Submissions',
                    '',
                    'fas fa-upload',
                    [
                   
                    ]
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Submissions List -->
                <div class="card submissions-card">
                    <div class="card-header submissions-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2 text-primary"></i>All Submissions
                                    <span class="badge bg-primary ms-2"><?php echo number_format($p['total']); ?></span>
                                </h5>
                            </div>
                            <?php if ($search || $statusFilter || $requirementId): ?>
                                <a href="submissions.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-redo me-1"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Enhanced Filter Section -->
                        <div class="filter-section">
                            <form method="GET" class="filter-form">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label for="requirement_id" class="form-label">
                                            <i class="fas fa-file-alt me-1 text-muted"></i>Requirement
                                        </label>
                                        <select class="form-select form-select-sm" id="requirement_id" name="requirement_id">
                                            <option value="">All Requirements</option>
                                            <?php foreach ($requirements as $req): ?>
                                                <option value="<?php echo $req['id']; ?>" <?php echo $requirementId == $req['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($req['title']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label for="status" class="form-label">
                                            <i class="fas fa-filter me-1 text-muted"></i>Status
                                        </label>
                                        <select class="form-select form-select-sm" id="status" name="status">
                                            <option value="">All Status</option>
                                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>
                                                <i class="fas fa-clock"></i> Pending
                                            </option>
                                            <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>
                                                <i class="fas fa-check"></i> Approved
                                            </option>
                                            <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>
                                                <i class="fas fa-times"></i> Rejected
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label for="search" class="form-label">
                                            <i class="fas fa-search me-1 text-muted"></i>Search
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white">
                                                <i class="fas fa-search text-muted"></i>
                                            </span>
                                            <input type="text" class="form-control" id="search" name="search" 
                                                   value="<?php echo htmlspecialchars($search); ?>" 
                                                   placeholder="Search by faculty name, email, or requirement...">
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                                <i class="fas fa-search me-1"></i>Search
                                            </button>
                                            <?php if ($search || $statusFilter || $requirementId): ?>
                                                <a href="submissions.php" class="btn btn-outline-secondary btn-sm" title="Clear filters">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php if (empty($submissions)): ?>
                            <div class="empty-state enhanced-empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-inbox"></i>
                                </div>
                                <h5 class="empty-state-title">No Submissions Found</h5>
                                <p class="empty-state-text">No file submissions match your current filters.</p>
                                <?php if ($search || $statusFilter || $requirementId): ?>
                                    <a href="submissions.php" class="btn btn-primary btn-sm mt-2">
                                        <i class="fas fa-redo me-1"></i>Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive submissions-table-wrapper">
                                <table class="table table-hover align-middle submissions-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="faculty-col">
                                                <i class="fas fa-user me-1 text-muted"></i>Faculty
                                            </th>
                                            <th class="requirement-col">
                                                <i class="fas fa-file-alt me-1 text-muted"></i>Requirement
                                            </th>
                                            <th class="d-none d-md-table-cell file-col">
                                                <i class="fas fa-file me-1 text-muted"></i>File
                                            </th>
                                            <th class="status-col">
                                                <i class="fas fa-info-circle me-1 text-muted"></i>Status
                                            </th>
                                            <th class="d-none d-lg-table-cell date-col">
                                                <i class="fas fa-calendar me-1 text-muted"></i>Submitted
                                            </th>
                                            <th class="d-none d-xl-table-cell review-col">
                                                <i class="fas fa-user-check me-1 text-muted"></i>Reviewed
                                            </th>
                                            <th class="actions-col text-end">
                                                <i class="fas fa-cog me-1 text-muted"></i>Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions as $sub): ?>
                                            <tr class="submission-row <?php echo $sub['status'] === 'pending' ? 'row-pending' : ''; ?>">
                                                <td data-label="Faculty" class="faculty-cell">
                                                    <div class="faculty-info">
                                                        <div class="faculty-avatar">
                                                            <?php 
                                                            $initials = strtoupper(substr($sub['first_name'], 0, 1) . substr($sub['last_name'], 0, 1));
                                                            echo $initials;
                                                            ?>
                                                        </div>
                                                        <div class="faculty-details">
                                                            <strong class="faculty-name"><?php echo htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name']); ?></strong>
                                                            <div class="text-muted small d-md-none mt-1">
                                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($sub['email']); ?>
                                                            </div>
                                                            <div class="text-muted small d-lg-none mt-1">
                                                                <i class="fas fa-calendar me-1"></i><?php echo formatDate($sub['submitted_at'], 'M j, Y'); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="Requirement" class="requirement-cell">
                                                    <div class="requirement-info">
                                                        <strong class="requirement-title"><?php echo htmlspecialchars($sub['requirement_title']); ?></strong>
                                                        <?php if ($sub['deadline']): ?>
                                                            <div class="text-muted small deadline-info d-md-none mt-1">
                                                                <i class="fas fa-clock me-1"></i>Deadline: <?php echo formatDate($sub['deadline'], 'M j, Y'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="text-muted small d-md-none mt-1">
                                                            <i class="fas fa-file me-1"></i>
                                                            <?php echo htmlspecialchars($sub['original_filename']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="d-none d-md-table-cell file-cell">
                                                    <div class="file-info">
                                                        <div class="file-icon-wrapper">
                                                            <i class="fas fa-file-pdf text-danger"></i>
                                                        </div>
                                                        <div class="file-details">
                                                            <div class="file-name" title="<?php echo htmlspecialchars($sub['original_filename']); ?>">
                                                                <?php echo htmlspecialchars($sub['original_filename']); ?>
                                                            </div>
                                                            <small class="text-muted file-size">
                                                                <i class="fas fa-hdd me-1"></i><?php echo $uploader->formatFileSize($sub['file_size']); ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="Status" class="status-cell">
                                                    <span class="status-badge status-badge-<?php 
                                                        echo $sub['status'] === 'approved' ? 'success' : 
                                                            ($sub['status'] === 'rejected' ? 'danger' : 'warning'); 
                                                    ?>">
                                                        <i class="fas fa-<?php 
                                                            echo $sub['status'] === 'approved' ? 'check-circle' : 
                                                                ($sub['status'] === 'rejected' ? 'times-circle' : 'clock'); 
                                                        ?> me-1"></i>
                                                        <?php echo ucfirst($sub['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="d-none d-lg-table-cell date-cell">
                                                    <div class="date-info">
                                                        <div class="date-value"><?php echo formatDate($sub['submitted_at'], 'M j, Y'); ?></div>
                                                        <small class="text-muted time-value">
                                                            <i class="fas fa-clock me-1"></i><?php echo date('g:i A', strtotime($sub['submitted_at'])); ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td class="d-none d-xl-table-cell review-cell">
                                                    <?php if ($sub['reviewed_at']): ?>
                                                        <div class="review-info">
                                                            <div class="review-date"><?php echo formatDate($sub['reviewed_at'], 'M j, Y'); ?></div>
                                                            <small class="text-muted reviewer-name">
                                                                <i class="fas fa-user me-1"></i>by <?php echo htmlspecialchars($sub['reviewer_first_name'] . ' ' . $sub['reviewer_last_name']); ?>
                                                            </small>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">
                                                            <i class="fas fa-minus-circle me-1"></i>Not reviewed
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Actions" class="actions-cell">
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn btn-action btn-view" 
                                                                onclick="viewSubmission(<?php echo $sub['id']; ?>)" 
                                                                title="View submission details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="download.php?file=<?php echo urlencode($sub['file_path']); ?>&name=<?php echo urlencode($sub['original_filename']); ?>" 
                                                           class="btn btn-action btn-download" 
                                                           title="Download file">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <?php if ($sub['status'] === 'pending'): ?>
                                                            <button type="button" class="btn btn-action btn-review" 
                                                                    onclick="reviewSubmission(<?php echo $sub['id']; ?>)" 
                                                                    title="Review submission">
                                                                <i class="fas fa-check-double"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php
                            // Pagination controls for submissions
                            if (!empty($p) && $p['totalPages'] > 1) {
                                echo renderPagination($p['page'], $p['totalPages']);
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="reviewModalLabel" style="
    color: white;
">
                        <i class="fas fa-clipboard-check me-2"></i>Review Submission
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="reviewForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="review">
                        <input type="hidden" name="submission_id" id="reviewSubmissionId">
                        
                        <div id="submissionDetailsLoader" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="text-muted mt-2">Loading submission details...</p>
                        </div>
                        
                        <div id="submissionDetails" style="display: none;">
                            <!-- Submission details will be loaded here -->
                        </div>

                        <div id="submissionHistory" class="mb-3" style="display: none;">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">
                                        <i class="fas fa-history me-2 text-primary"></i>Previous Versions
                                    </h6>
                                    <div id="historyContent">
                                        <!-- Previous versions will be loaded here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="review-form-section">
                            <div class="mb-3">
                                <label for="reviewStatus" class="form-label fw-bold">
                                    <i class="fas fa-check-circle me-1 text-primary"></i>Review Status <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="reviewStatus" name="status" required>
                                    <option value="">Select Status</option>
                                    <option value="approved">
                                        <i class="fas fa-check"></i> Approved
                                    </option>
                                    <option value="rejected">
                                        <i class="fas fa-times"></i> Rejected
                                    </option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="admin_notes" class="form-label fw-bold">
                                    <i class="fas fa-comment-alt me-1 text-primary"></i>Admin Notes
                                </label>
                                <textarea class="form-control" id="admin_notes" name="admin_notes" rows="4" 
                                          placeholder="Add any notes or feedback for the faculty member..."></textarea>
                                <small class="text-muted">This feedback will be sent to the faculty member via email and notification.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-1"></i>Submit Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="viewModalLabel" style="
    color: white;
">
                        <i class="fas fa-eye me-2"></i>Submission Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewSubmissionDetails">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted mt-2">Loading submission details...</p>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <style>
        /* Enhanced Submissions Page Styles */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s, box-shadow 0.2s;
            border-left: 4px solid;
        }
        .stat-card-warning { border-left-color: #f59e0b; }
        .stat-card-success { border-left-color: #10b981; }
        .stat-card-danger { border-left-color: #ef4444; }
        .stat-card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        .stat-card-warning .stat-card-icon { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .stat-card-success .stat-card-icon { background: linear-gradient(135deg, #10b981, #34d399); }
        .stat-card-danger .stat-card-icon { background: linear-gradient(135deg, #ef4444, #f87171); }
        .stat-card-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1;
        }
        .stat-card-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        .submissions-card {
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-radius: 12px;
            overflow: hidden;
        }
        .submissions-header {
            background: linear-gradient(135deg, #003366 0%, #005599 100%);
            color: white;
            border: none;
            padding: 1.25rem 1.5rem;
        }
        .submissions-header h5 {
            color: white;
            margin: 0;
        }
        .submissions-header .badge {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        
        .filter-section {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .filter-form .form-label {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        .filter-form .form-select,
        .filter-form .form-control {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .filter-form .form-select:focus,
        .filter-form .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        .input-group-text {
            border: 1px solid var(--border-color);
            border-right: none;
        }
        
        .submissions-table-wrapper {
            border-radius: 8px;
            overflow: hidden;
        }
        .submissions-table {
            margin: 0;
        }
        .submissions-table thead th {
            background: var(--light-gray);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dark);
            padding: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        .submissions-table tbody tr {
            transition: background-color 0.2s;
            border-bottom: 1px solid var(--border-light);
        }
        .submissions-table tbody tr:hover {
            background: var(--light-gray);
        }
        .submissions-table tbody tr.row-pending {
            background: rgba(245, 158, 11, 0.05);
        }
        .submissions-table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .faculty-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .faculty-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        .faculty-name {
            display: block;
            color: var(--text-dark);
            font-size: 0.9375rem;
        }
        .faculty-email {
            font-size: 0.8125rem;
        }
        
        .requirement-title {
            color: var(--text-dark);
            font-size: 0.9375rem;
            display: block;
        }
        .deadline-info {
            font-size: 0.8125rem;
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .file-icon-wrapper {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .file-name {
            font-size: 0.875rem;
            color: var(--text-dark);
            display: block;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .file-size {
            font-size: 0.8125rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        .status-badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        .status-badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }
        
        .date-info, .review-info {
            font-size: 0.875rem;
        }
        .date-value, .review-date {
            color: var(--text-dark);
            font-weight: 500;
        }
        .time-value, .reviewer-name {
            font-size: 0.8125rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .btn-action {
            width: 32px;
            height: 32px;
            padding: 0;
            border-radius: 4px;
            font-weight: 400;
            transition: all 0.2s;
            border: 1px solid var(--border-color);
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-medium);
            font-size: 0.875rem;
        }
        .btn-action:hover {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
            background: rgba(0, 51, 102, 0.05);
            transform: translateY(-1px);
        }
        .btn-action:active {
            transform: translateY(0);
        }
        .btn-view {
            color: var(--text-medium);
        }
        .btn-view:hover {
            color: #0066cc;
            border-color: #0066cc;
        }
        .btn-download {
            color: var(--text-medium);
        }
        .btn-download:hover {
            color: #059669;
            border-color: #059669;
        }
        .btn-review {
            color: var(--text-medium);
        }
        .btn-review:hover {
            color: #d97706;
            border-color: #d97706;
        }
        
        .enhanced-empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            border-radius: 50%;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--text-muted);
        }
        .empty-state-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        .empty-state-text {
            color: var(--text-muted);
            margin-bottom: 0;
        }
        
        /* Enhanced Modal Styles */
        .modal-content {
            border: none;
            border-radius: 12px;
            overflow: hidden;
        }
        .modal-header {
            border-bottom: none;
            padding: 1.5rem;
        }
        .modal-body {
            padding: 1.5rem;
        }
        #reviewModal .modal-body {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }
        .modal-footer {
            border-top: 1px solid var(--border-light);
            padding: 1rem 1.5rem;
        }
        .review-form-section {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 1.5rem;
        }
        
        /* Loading States */
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        /* Responsive Improvements */
        @media (max-width: 768px) {
            .stat-card {
                padding: 1rem;
            }
            .stat-card-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
            .stat-card-value {
                font-size: 1.5rem;
            }
            .filter-section {
                padding: 1rem;
            }
            .action-buttons {
                gap: 0.375rem;
            }
            .btn-action {
                width: 36px;
                height: 36px;
            }
        }
        
        /* Card Enhancements in Modals */
        .submission-detail-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .submission-detail-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-light);
        }
        .submission-detail-item:last-child {
            border-bottom: none;
        }
        .submission-detail-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 120px;
            font-size: 0.875rem;
        }
        .submission-detail-value {
            color: var(--text-medium);
            flex: 1;
            font-size: 0.875rem;
        }
        
        /* PDF Viewer Styles */
        .pdf-viewer-container {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 1rem;
        }
        .pdf-viewer-header {
            background: var(--light-gray);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .pdf-viewer-header h6 {
            margin: 0;
            color: var(--text-dark);
            font-weight: 600;
        }
        .pdf-viewer-actions {
            display: flex;
            gap: 0.5rem;
        }
        .pdf-viewer-wrapper {
            position: relative;
            width: 100%;
            height: 600px;
            background: #525252;
            overflow: hidden;
        }
        .pdf-viewer-iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
        
        @media (max-width: 768px) {
            .pdf-viewer-wrapper {
                height: 400px;
            }
            .pdf-viewer-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .pdf-viewer-actions {
                width: 100%;
            }
            .pdf-viewer-actions .btn {
                flex: 1;
            }
        }
    </style>
    <script>
        function reviewSubmission(submissionId) {
            document.getElementById('reviewSubmissionId').value = submissionId;
            const loader = document.getElementById('submissionDetailsLoader');
            const details = document.getElementById('submissionDetails');
            
            loader.style.display = 'block';
            details.style.display = 'none';
            
            // Reset form
            document.getElementById('reviewStatus').value = '';
            document.getElementById('admin_notes').value = '';
            
            // Load submission details
            fetch(`get_submission.php?id=${submissionId}`)
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.success) {
                        const submission = data.submission;
                        const statusClass = submission.status === 'approved' ? 'success' : 
                                          (submission.status === 'rejected' ? 'danger' : 'warning');
                        const statusIcon = submission.status === 'approved' ? 'check-circle' : 
                                         (submission.status === 'rejected' ? 'times-circle' : 'clock');
                        
                        document.getElementById('submissionDetails').innerHTML = `
                            <div class="submission-detail-card">
                                <h6 class="mb-3">
                                    <i class="fas fa-file-alt me-2 text-primary"></i>Current Submission
                                    <span class="badge bg-${statusClass} ms-2">Version ${submission.version}</span>
                                </h6>
                                <div class="submission-detail-item">
                                    <span class="submission-detail-label">
                                        <i class="fas fa-user me-1"></i>Faculty:
                                    </span>
                                    <span class="submission-detail-value">${submission.first_name} ${submission.last_name}</span>
                                </div>
                                <div class="submission-detail-item">
                                    <span class="submission-detail-label">
                                        <i class="fas fa-envelope me-1"></i>Email:
                                    </span>
                                    <span class="submission-detail-value">${submission.email}</span>
                                </div>
                                <div class="submission-detail-item">
                                    <span class="submission-detail-label">
                                        <i class="fas fa-file-alt me-1"></i>Requirement:
                                    </span>
                                    <span class="submission-detail-value">${submission.requirement_title}</span>
                                </div>
                                <div class="submission-detail-item">
                                    <span class="submission-detail-label">
                                        <i class="fas fa-file me-1"></i>File:
                                    </span>
                                    <span class="submission-detail-value">
                                        <a href="download.php?file=${encodeURIComponent(submission.file_path)}&name=${encodeURIComponent(submission.original_filename)}" 
                                           class="text-decoration-none">
                                            <i class="fas fa-download me-1"></i>${submission.original_filename}
                                        </a>
                                        <small class="text-muted d-block mt-1">${submission.file_size_formatted}</small>
                                    </span>
                                </div>
                                <div class="submission-detail-item">
                                    <span class="submission-detail-label">
                                        <i class="fas fa-calendar me-1"></i>Submitted:
                                    </span>
                                    <span class="submission-detail-value">${submission.submitted_at_formatted}</span>
                                </div>
                                <div class="submission-detail-item">
                                    <span class="submission-detail-label">
                                        <i class="fas fa-info-circle me-1"></i>Status:
                                    </span>
                                    <span class="submission-detail-value">
                                        <span class="status-badge status-badge-${statusClass}">
                                            <i class="fas fa-${statusIcon} me-1"></i>${submission.status.charAt(0).toUpperCase() + submission.status.slice(1)}
                                        </span>
                                    </span>
                                </div>
                            </div>
                        `;
                        details.style.display = 'block';

                        // Show previous versions if they exist
                        const historyDiv = document.getElementById('submissionHistory');
                        const historyContent = document.getElementById('historyContent');
                        
                        if (data.previousVersions && data.previousVersions.length > 0) {
                            let historyHtml = '';
                            data.previousVersions.forEach(version => {
                                const vStatusClass = version.status === 'approved' ? 'success' : 
                                                   (version.status === 'rejected' ? 'danger' : 'warning');
                                const vStatusIcon = version.status === 'approved' ? 'check-circle' : 
                                                  (version.status === 'rejected' ? 'times-circle' : 'clock');
                                
                                historyHtml += `
                                    <div class="card mb-2 border">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-code-branch me-1 text-muted"></i>Version ${version.version}
                                                </h6>
                                                <span class="status-badge status-badge-${vStatusClass}">
                                                    <i class="fas fa-${vStatusIcon} me-1"></i>${version.status}
                                                </span>
                                            </div>
                                            <div class="submission-detail-item">
                                                <span class="submission-detail-label">Submitted:</span>
                                                <span class="submission-detail-value">${version.submitted_at_formatted}</span>
                                            </div>
                                            <div class="submission-detail-item">
                                                <span class="submission-detail-label">File:</span>
                                                <span class="submission-detail-value">${version.original_filename}</span>
                                            </div>
                                            ${version.reviewed_at ? `
                                                <div class="submission-detail-item">
                                                    <span class="submission-detail-label">Reviewed:</span>
                                                    <span class="submission-detail-value">${version.reviewed_at_formatted}</span>
                                                </div>
                                                <div class="submission-detail-item">
                                                    <span class="submission-detail-label">Reviewed by:</span>
                                                    <span class="submission-detail-value">${version.reviewer_first_name} ${version.reviewer_last_name}</span>
                                                </div>
                                            ` : ''}
                                            ${version.admin_notes ? `
                                                <div class="submission-detail-item">
                                                    <span class="submission-detail-label">Notes:</span>
                                                    <span class="submission-detail-value">${version.admin_notes}</span>
                                                </div>
                                            ` : ''}
                                            <div class="mt-2">
                                                <a href="download.php?file=${encodeURIComponent(version.file_path)}&name=${encodeURIComponent(version.original_filename)}" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-download me-1"></i>Download File
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                            historyContent.innerHTML = historyHtml;
                            historyDiv.style.display = 'block';
                        } else {
                            historyDiv.style.display = 'none';
                        }
                        new bootstrap.Modal(document.getElementById('reviewModal')).show();
                    } else {
                        showError('Failed to load submission details.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    loader.style.display = 'none';
                    showError('Failed to load submission details.');
                });
        }
        
        function viewSubmission(submissionId) {
            const modalBody = document.getElementById('viewSubmissionDetails');
            modalBody.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading submission details...</p>
                </div>
            `;
            
            // Load submission details
            fetch(`get_submission.php?id=${submissionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const submission = data.submission;
                        const statusClass = submission.status === 'approved' ? 'success' : 
                                          (submission.status === 'rejected' ? 'danger' : 'warning');
                        const statusIcon = submission.status === 'approved' ? 'check-circle' : 
                                         (submission.status === 'rejected' ? 'times-circle' : 'clock');
                        
                        // Check if file is PDF
                        const fileExtension = submission.original_filename.split('.').pop().toLowerCase();
                        const isPDF = fileExtension === 'pdf';
                        const viewFileUrl = `view_file.php?file=${encodeURIComponent(submission.file_path)}&name=${encodeURIComponent(submission.original_filename)}`;
                        const downloadFileUrl = `download.php?file=${encodeURIComponent(submission.file_path)}&name=${encodeURIComponent(submission.original_filename)}`;
                        
                        modalBody.innerHTML = `
                            <div class="submission-detail-card mb-3">
                                <h6 class="mb-3">
                                    <i class="fas fa-info-circle me-2 text-info"></i>Submission Information
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-user me-1"></i>Faculty:
                                            </span>
                                            <span class="submission-detail-value">${submission.first_name} ${submission.last_name}</span>
                                        </div>
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-envelope me-1"></i>Email:
                                            </span>
                                            <span class="submission-detail-value">${submission.email}</span>
                                        </div>
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-file-alt me-1"></i>Requirement:
                                            </span>
                                            <span class="submission-detail-value">${submission.requirement_title}</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-file me-1"></i>File:
                                            </span>
                                            <span class="submission-detail-value">${submission.original_filename}</span>
                                        </div>
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-hdd me-1"></i>Size:
                                            </span>
                                            <span class="submission-detail-value">${submission.file_size_formatted}</span>
                                        </div>
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-calendar me-1"></i>Submitted:
                                            </span>
                                            <span class="submission-detail-value">${submission.submitted_at_formatted}</span>
                                        </div>
                                        <div class="submission-detail-item">
                                            <span class="submission-detail-label">
                                                <i class="fas fa-info-circle me-1"></i>Status:
                                            </span>
                                            <span class="submission-detail-value">
                                                <span class="status-badge status-badge-${statusClass}">
                                                    <i class="fas fa-${statusIcon} me-1"></i>${submission.status.charAt(0).toUpperCase() + submission.status.slice(1)}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                ${submission.requirement_description ? `
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="submission-detail-label mb-2">
                                            <i class="fas fa-align-left me-1"></i>Requirement Description:
                                        </div>
                                        <p class="text-muted mb-0">${submission.requirement_description}</p>
                                    </div>
                                ` : ''}
                                <div class="text-center mt-3">
                                    ${isPDF ? `
                                        <a href="${viewFileUrl}" target="_blank" class="btn btn-info btn-lg me-2">
                                            <i class="fas fa-external-link-alt me-2"></i>Open in New Tab
                                        </a>
                                    ` : ''}
                                    <a href="${downloadFileUrl}" class="btn btn-primary btn-lg">
                                        <i class="fas fa-download me-2"></i>Download File
                                    </a>
                                </div>
                            </div>
                            ${isPDF ? `
                               
                            ` : ''}
                        `;
                        new bootstrap.Modal(document.getElementById('viewModal')).show();
                    } else {
                        modalBody.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>Failed to load submission details.
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>Failed to load submission details.
                        </div>
                    `;
                });
        }
    </script>
</body>
</html>







