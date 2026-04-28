<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';
require_once '../includes/notifications.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();
$uploader = new FileUploader();

// Ensure attachments table exists
ensureRequirementAttachmentsTable();

$action = $_GET['action'] ?? 'list';
$requirementId = $_GET['id'] ?? null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateFormToken($_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid form submission. Please try again.";
        redirect('requirements.php');
    }

    $action = $_POST['action'] ?? 'submit';
    
    if ($action === 'submit') {
        $requirementId = (int)$_POST['requirement_id'];
        
        // Get requirement details AND verify it's assigned to this faculty member
        $stmt = $db->prepare("
            SELECT r.* 
            FROM requirements r
            INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
            WHERE r.id = ? AND r.is_active = 1 AND fr.faculty_id = ?
        ");
        $stmt->execute([$requirementId, $_SESSION['user_id']]);
        $requirement = $stmt->fetch();
        
        if (!$requirement) {
            $_SESSION['error'] = 'Requirement not found, inactive, or not assigned to you.';
            header('Location: requirements.php');
            exit();
        }
        
        // Check if deadline has passed - block all submissions once deadline is met
        $currentTime = time();
        if ($requirement['deadline']) {
            $deadlineTime = strtotime($requirement['deadline']);
            if ($deadlineTime <= $currentTime) {
                $_SESSION['error'] = 'Submission deadline has passed for this requirement. Submissions are no longer accepted.';
                header('Location: requirements.php');
                exit();
            }
        }
        
        // Check if already submitted â€” but allow resubmission when the latest submission was rejected (and deadline hasn't passed)
        $stmt = $db->prepare("SELECT id, status, version FROM faculty_submissions WHERE faculty_id = ? AND requirement_id = ? ORDER BY version DESC LIMIT 1");
        $stmt->execute([$_SESSION['user_id'], $requirementId]);
        $latest = $stmt->fetch();
        if ($latest && isset($latest['status']) && $latest['status'] !== 'rejected') {
            // If the latest submission is pending or approved, block additional submissions
            $_SESSION['error'] = 'You have already submitted a file for this requirement.';
            header('Location: requirements.php');
            exit();
        }
        
        // If resubmitting after rejection, still check deadline (redundant check but ensures consistency)
        if ($latest && $latest['status'] === 'rejected') {
            if ($requirement['deadline']) {
                $deadlineTime = strtotime($requirement['deadline']);
                if ($deadlineTime <= $currentTime) {
                    $_SESSION['error'] = 'Cannot resubmit after the deadline has passed.';
                    header('Location: requirements.php');
                    exit();
                }
            }
        }
        
        // Handle file upload
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = array_map('trim', explode(',', $requirement['file_types']));
            // Normalize max size: if DB somehow has MB value (small integer), convert to bytes
            $rawMax = isset($requirement['max_file_size']) ? (int)$requirement['max_file_size'] : 0;
            if ($rawMax > 0 && $rawMax < 1048576) {
                $maxSize = $rawMax * 1024 * 1024;
            } else {
                $maxSize = $rawMax > 0 ? $rawMax : MAX_FILE_SIZE;
            }

            $result = $uploader->uploadFile($_FILES['file'], 'submissions', $allowedTypes, $maxSize);
            
            if ($result['success']) {
                // Check if this is a resubmission
                $stmt = $db->prepare("SELECT id, version FROM faculty_submissions 
                                    WHERE faculty_id = ? AND requirement_id = ? 
                                    ORDER BY version DESC LIMIT 1");
                $stmt->execute([$_SESSION['user_id'], $requirementId]);
                $existingSubmission = $stmt->fetch();
                
                $version = 1;
                $previousSubmissionId = null;
                
                if ($existingSubmission) {
                    $version = $existingSubmission['version'] + 1;
                    $previousSubmissionId = $existingSubmission['id'];
                }
                
                // Save submission to database
                $stmt = $db->prepare("INSERT INTO faculty_submissions (faculty_id, requirement_id, file_path, original_filename, file_size, version, previous_submission_id) VALUES (?, ?, ?, ?, ?, ?, ?)");

                try {
                    if ($stmt->execute([
                        $_SESSION['user_id'],
                        $requirementId,
                        $result['file_path'],
                        $result['original_filename'],
                        $result['file_size'],
                        $version,
                        $previousSubmissionId
                    ])) {
                        $submissionId = $db->lastInsertId();
                        $_SESSION['success'] = 'File submitted successfully!';
                        logAction('FILE_SUBMITTED', "Submitted file for requirement: " . $requirement['title']);

                        // Notify all admins about the new submission
                        try {
                            // Get faculty name for the notification
                            $facultyStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                            $facultyStmt->execute([$_SESSION['user_id']]);
                            $facultyData = $facultyStmt->fetch();
                            $facultyName = $facultyData ? trim($facultyData['first_name'] . ' ' . $facultyData['last_name']) : 'Faculty Member';
                            
                            $notificationManager = getNotificationManager();
                            $notificationManager->notifyAdminsNewSubmission(
                                $facultyName,
                                $requirement['title'],
                                $submissionId,
                                $requirementId
                            );
                        } catch (Exception $e) {
                            error_log('Failed to notify admins about submission: ' . $e->getMessage());
                        }
                    } else {
                        $_SESSION['error'] = 'Failed to save submission. Please try again.';
                    }
                } catch (PDOException $e) {
                    // Detect duplicate key (unique constraint) which prevents multiple versions being inserted
                    $sqlState = $e->getCode();
                    error_log('Submission insert failed: ' . $e->getMessage());
                    // SQLSTATE 23000 indicates integrity constraint violation (duplicate entry)
                    if ($sqlState === '23000') {
                        // This likely means there is a UNIQUE index on (faculty_id, requirement_id)
                        // Fallback: update the existing submission row so admin sees the new file even if DB can't store multiple rows.
                        if (!empty($existingSubmission) && isset($existingSubmission['id'])) {
                            try {
                                $updateStmt = $db->prepare("UPDATE faculty_submissions SET file_path = ?, original_filename = ?, file_size = ?, version = version + 1, submitted_at = NOW(), status = 'pending', admin_notes = NULL, reviewed_at = NULL, reviewed_by = NULL WHERE faculty_id = ? AND requirement_id = ?");
                                if ($updateStmt->execute([
                                    $result['file_path'],
                                    $result['original_filename'],
                                    $result['file_size'],
                                    $_SESSION['user_id'],
                                    $requirementId
                                ])) {
                                    $submissionId = $existingSubmission['id'];
                                    $_SESSION['success'] = 'File resubmitted successfully (updated existing record).';
                                    logAction('FILE_RESUBMITTED_UPDATE', "Updated existing submission for requirement: " . $requirement['title']);

                                    // Notify admins about the resubmission
                                    try {
                                        // Get faculty name for the notification
                                        $facultyStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                                        $facultyStmt->execute([$_SESSION['user_id']]);
                                        $facultyData = $facultyStmt->fetch();
                                        $facultyName = $facultyData ? trim($facultyData['first_name'] . ' ' . $facultyData['last_name']) : 'Faculty Member';
                                        
                                        $notificationManager = getNotificationManager();
                                        $notificationManager->notifyAdminsNewSubmission(
                                            $facultyName,
                                            $requirement['title'],
                                            $submissionId,
                                            $requirementId
                                        );
                                    } catch (Exception $e) {
                                        error_log('Failed to notify admins about resubmission: ' . $e->getMessage());
                                    }
                                } else {
                                    $_SESSION['error'] = 'Resubmission failed: unable to update existing submission.';
                                    logAction('SUBMISSION_ERROR', "Failed to update existing submission for faculty_id={$_SESSION['user_id']} requirement_id={$requirementId}");
                                }
                            } catch (PDOException $ex) {
                                error_log('Fallback update failed: ' . $ex->getMessage());
                                $_SESSION['error'] = 'Resubmission failed due to a database error. Please contact support.';
                                logAction('SUBMISSION_ERROR', 'Fallback PDOException: ' . $ex->getMessage());
                            }
                        } else {
                            $_SESSION['error'] = 'Resubmission failed: existing submission not found and DB prevents creating a new one. Please contact an administrator.';
                            logAction('SUBMISSION_ERROR', "Duplicate key and no existingSubmission found for faculty_id={$_SESSION['user_id']} requirement_id={$requirementId}");
                        }
                    } else {
                        $_SESSION['error'] = 'Failed to save submission. Please try again.';
                        logAction('SUBMISSION_ERROR', 'PDOException: ' . $e->getMessage());
                    }
                }
            } else {
                $_SESSION['error'] = $result['message'];
            }
        } else {
            $_SESSION['error'] = 'Please select a file to upload.';
        }
        
        header('Location: requirements.php');
        exit();
    }

    if ($action === 'attach') {
        $requirementId = (int)$_POST['requirement_id'];

        // Get requirement details AND verify it's assigned to this faculty member
        $stmt = $db->prepare("
            SELECT r.* 
            FROM requirements r
            INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
            WHERE r.id = ? AND r.is_active = 1 AND fr.faculty_id = ?
        ");
        $stmt->execute([$requirementId, $_SESSION['user_id']]);
        $requirement = $stmt->fetch();

        if (!$requirement) {
            $_SESSION['error'] = 'Requirement not found, inactive, or not assigned to you.';
            header('Location: requirements.php');
            exit();
        }

        // Check if deadline has passed - block all attachments once deadline is met
        $currentTime = time();
        if ($requirement['deadline']) {
            $deadlineTime = strtotime($requirement['deadline']);
            if ($deadlineTime <= $currentTime) {
                $_SESSION['error'] = 'Deadline has passed for this requirement. Attachments are no longer accepted.';
                header('Location: requirements.php');
                exit();
            }
        }

        // Handle file upload for requirement attachment
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = array_map('trim', explode(',', $requirement['file_types']));
            $rawMax = isset($requirement['max_file_size']) ? (int)$requirement['max_file_size'] : 0;
            if ($rawMax > 0 && $rawMax < 1048576) {
                $maxSize = $rawMax * 1024 * 1024;
            } else {
                $maxSize = $rawMax > 0 ? $rawMax : MAX_FILE_SIZE;
            }

            $result = $uploader->uploadFile($_FILES['file'], 'requirements', $allowedTypes, $maxSize);

            if ($result['success']) {
                // Save attachment metadata
                $stmt = $db->prepare("INSERT INTO requirement_attachments (requirement_id, faculty_id, file_path, original_filename, file_size) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([
                    $requirementId,
                    $_SESSION['user_id'],
                    $result['file_path'],
                    $result['original_filename'],
                    $result['file_size']
                ])) {
                    $_SESSION['success'] = 'File attached to requirement successfully!';
                    logAction('REQUIREMENT_ATTACHMENT_UPLOADED', "Uploaded attachment for requirement: " . $requirement['title']);
                } else {
                    $_SESSION['error'] = 'Failed to save attachment. Please try again.';
                }
            } else {
                $_SESSION['error'] = $result['message'];
            }
        } else {
            $_SESSION['error'] = 'Please select a file to upload.';
        }

        header('Location: requirements.php');
        exit();
    }
}

// Get active requirements assigned to this faculty member
$stmt = $db->prepare("
    SELECT r.* 
    FROM requirements r
    INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
    WHERE r.is_active = 1 
    AND fr.faculty_id = ?
    ORDER BY r.deadline ASC, r.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$requirements = $stmt->fetchAll();

// Normalize max_file_size for requirements (convert legacy MB values to bytes if needed)
foreach ($requirements as &$req) {
    $raw = isset($req['max_file_size']) ? (int)$req['max_file_size'] : 0;
    if ($raw > 0 && $raw < 1048576) {
            // value looks like MB (e.g., 5) - convert to bytes
        $req['max_file_size_normalized'] = $raw * 1024 * 1024;
    } else {
        $req['max_file_size_normalized'] = $raw > 0 ? $raw : 5242880;
    }
}
unset($req);

// Get faculty submissions
$stmt = $db->prepare("SELECT fs.*, r.title as requirement_title, r.deadline FROM faculty_submissions fs JOIN requirements r ON fs.requirement_id = r.id WHERE fs.faculty_id = ? ORDER BY fs.submitted_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$submissions = $stmt->fetchAll();

// Get attachments uploaded by this faculty for requirements
$stmt = $db->prepare("SELECT * FROM requirement_attachments WHERE faculty_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$attachments = $stmt->fetchAll();

// Get specific requirement for submission (only if assigned to this faculty)
$submitRequirement = null;
if ($requirementId) {
    $stmt = $db->prepare("
        SELECT r.* 
        FROM requirements r
        INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
        WHERE r.id = ? AND r.is_active = 1 AND fr.faculty_id = ?
    ");
    $stmt->execute([$requirementId, $_SESSION['user_id']]);
    $submitRequirement = $stmt->fetch();

    // Normalize the max_file_size for the specific requirement
    if ($submitRequirement) {
        $raw = isset($submitRequirement['max_file_size']) ? (int)$submitRequirement['max_file_size'] : 0;
        if ($raw > 0 && $raw < 1048576) {
            $submitRequirement['max_file_size_normalized'] = $raw * 1024 * 1024;
        } else {
            $submitRequirement['max_file_size_normalized'] = $raw > 0 ? $raw : 5242880;
        }
    }
}

// Pre-generate a CSRF token for the client-side upload modal
$clientFormToken = generateFormToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <title>Requirements - WPU Faculty and Staff System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/requirements.css', true); ?>" rel="stylesheet">
    <!-- CRITICAL: Override Bootstrap modals to prevent blocking buttons -->
    <link href="<?php echo asset_url('css/bootstrap-modal-override.css', true); ?>" rel="stylesheet">
    <style>
        /* CRITICAL FIX: Ensure Bootstrap modals don't block buttons when closed */
        body:not(.modal-open) .modal,
        body:not(.modal-open) .modal.fade,
        body:not(.modal-open) .modal:not(.show) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            position: fixed !important;
            top: -99999px !important;
            left: -99999px !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* CRITICAL FIX: Ensure Bootstrap backdrops don't block buttons when modals are closed */
        .modal-backdrop:not(.show),
        .modal-backdrop[style*="display: none"],
        body:not(.modal-open) .modal-backdrop,
        body:not(.modal-open) .modal-backdrop.fade {
            display: none !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            position: fixed !important;
        }
        
        /* Ensure modals are only interactive when showing */
        .modal.show,
        body.modal-open .modal.show {
            pointer-events: auto !important;
            z-index: 1055 !important;
        }
        
        /* Ensure backdrops are only interactive when showing */
        .modal-backdrop.show,
        body.modal-open .modal-backdrop.show {
            pointer-events: auto !important;
            z-index: 1040 !important;
        }
        
        /* CRITICAL: Force Bootstrap modals to be non-blocking when closed - MUST be before button rules */
        body:not(.modal-open) .modal,
        body:not(.modal-open) .modal.fade,
        body:not(.modal-open) .modal:not(.show) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            position: fixed !important;
            top: -99999px !important;
            left: -99999px !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Ensure buttons are always clickable ABOVE Bootstrap when modals are closed */
        body:not(.modal-open) .requirement-card-footer {
            z-index: 1098 !important;
            position: relative !important;
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0) !important;
        }
        
        body:not(.modal-open) .action-buttons {
            z-index: 1099 !important;
            position: relative !important;
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0) !important;
        }
        
        body:not(.modal-open) .btn-action,
        body:not(.modal-open) .submit-requirement-btn,
        body:not(.modal-open) .attach-requirement-btn,
        body:not(.modal-open) .action-buttons .btn,
        body:not(.modal-open) .action-buttons button,
        body:not(.modal-open) .action-buttons a,
        body:not(.modal-open) button:not(.modal button):not(:disabled),
        body:not(.modal-open) .btn:not(.modal .btn):not(:disabled) {
            z-index: 1100 !important; /* Above Bootstrap's 1055 */
            position: relative !important;
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0) !important; /* Force new stacking context */
        }
        
        /* CRITICAL: Ensure main content is not blocked by hidden modals */
        body:not(.modal-open) .main-content,
        body:not(.modal-open) .main-content *:not(.modal):not(.modal *) {
            position: relative !important;
            z-index: auto !important;
            pointer-events: auto !important;
        }
        
        /* Ensure requirement cards don't create stacking context issues */
        .requirement-card {
            position: relative;
            z-index: 1;
        }
        
        .requirement-card-footer {
            position: relative;
            z-index: 1098 !important; /* Above Bootstrap but below buttons */
            isolation: isolate !important; /* Create new stacking context */
            transform: translateZ(0); /* Force hardware acceleration */
        }
        
        .action-buttons {
            position: relative;
            z-index: 1099 !important; /* Above footer but below buttons */
            pointer-events: auto !important;
            isolation: isolate !important;
            transform: translateZ(0); /* Force hardware acceleration */
        }
        
        .action-buttons .btn,
        .action-buttons button {
            position: relative;
            z-index: 1100 !important; /* Above Bootstrap's 1055 */
            pointer-events: auto !important;
            touch-action: manipulation !important;
            isolation: isolate !important;
            transform: translateZ(0); /* Force new stacking context */
        }
        
        /* Mobile specific fixes - ABOVE Bootstrap */
        @media (max-width: 991px) {
            /* CRITICAL: Hide Bootstrap modals completely when closed on mobile */
            body:not(.modal-open) .modal,
            body:not(.modal-open) .modal.fade,
            body:not(.modal-open) .modal:not(.show) {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                z-index: -9999 !important;
                position: fixed !important;
                top: -99999px !important;
                left: -99999px !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* CRITICAL: Ensure buttons are clickable on mobile ABOVE Bootstrap */
            .requirement-card-footer {
                z-index: 1098 !important;
                position: relative !important;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            .action-buttons {
                z-index: 1099 !important;
                position: relative !important;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            .btn-action,
            .submit-requirement-btn,
            .attach-requirement-btn,
            button:not(.modal button):not(:disabled),
            .btn:not(.modal .btn):not(:disabled) {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                touch-action: manipulation !important;
                isolation: isolate !important;
                transform: translateZ(0) !important; /* Force new stacking context */
            }
            
            /* Ensure main content is interactive and not blocked */
            .main-content {
                position: relative;
                z-index: 1;
                pointer-events: auto !important;
            }
            
            .main-content *:not(.modal):not(.modal *) {
                pointer-events: auto !important;
            }
        }
        /* Remove excessive padding from Cancel button in modals */
        .btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        .btn-outline-secondary[data-bs-dismiss="modal"] {
            padding: 0.375rem 0.75rem !important;
        }
    </style>
</head>
<body class="layout-faculty requirements-page">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header requirements-page-header">
                    <div class="page-title">
                        <i class="fas fa-tasks" aria-hidden="true"></i>
                        <span>Requirements</span>
                    </div>
                    <p class="page-subtitle requirements-subtitle">View and submit documents for your assigned requirements. Tap a card to submit or view details.</p>
                </div>

                <?php displayMessage(); ?>

                <!-- Active Requirements -->
                <section class="card mb-4 requirements-card-container" aria-labelledby="active-requirements-heading">
                    <div class="card-header requirements-header">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <h2 id="active-requirements-heading" class="requirements-heading mb-0">
                                <i class="fas fa-tasks me-2 text-primary" aria-hidden="true"></i>Active Requirements
                                <?php if (!empty($requirements)): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-2" aria-label="<?php echo count($requirements); ?> requirements"><?php echo count($requirements); ?></span>
                                <?php endif; ?>
                            </h2>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($requirements)): ?>
                            <div class="requirements-empty-state">
                                <div class="empty-icon-wrapper">
                                    <i class="fas fa-clipboard-list"></i>
                                </div>
                                <h5 class="empty-title">No Active Requirements</h5>
                                <p class="empty-message">There are no active requirements assigned to you at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($requirements as $requirement): ?>
                                    <?php
                                    $submission = array_filter($submissions, function($s) use ($requirement) {
                                        return $s['requirement_id'] == $requirement['id'];
                                    });
                                    $submission = reset($submission);
                                    // Check if deadline has been met (using <= to include exact deadline time)
                                    $isOverdue = $requirement['deadline'] && strtotime($requirement['deadline']) <= time();
                                    $isDueSoon = $requirement['deadline'] && !$isOverdue && strtotime($requirement['deadline']) <= strtotime('+7 days');
                                    
                                    // Calculate deadline progress
                                    $deadlineProgress = 100;
                                    $daysLeft = 0;
                                    if ($requirement['deadline'] && !$isOverdue) {
                                        $deadline = strtotime($requirement['deadline']);
                                        $now = time();
                                        $daysLeft = max(0, ceil(($deadline - $now) / 86400));
                                        
                                        // Calculate progress percentage
                                        $created = isset($requirement['created_at']) ? strtotime($requirement['created_at']) : $now;
                                        $total = $deadline - $created;
                                        $remaining = $deadline - $now;
                                        if ($total > 0) {
                                            $deadlineProgress = max(0, min(100, ($remaining / $total) * 100));
                                        }
                                    } elseif ($requirement['deadline'] && $isOverdue) {
                                        $deadline = strtotime($requirement['deadline']);
                                        $now = time();
                                        $daysLeft = ceil(($now - $deadline) / 86400);
                                    }

                                    // Find attachment uploaded by this faculty for this requirement (if any)
                                    $attachment = array_filter($attachments ?? [], function($a) use ($requirement) {
                                        return $a['requirement_id'] == $requirement['id'];
                                    });
                                    $attachment = reset($attachment);
                                    
                                    // Status class
                                    $statusClass = 'secondary';
                                    $statusIcon = 'fa-circle';
                                    if ($submission) {
                                        if ($submission['status'] === 'approved') {
                                            $statusClass = 'success';
                                            $statusIcon = 'fa-check-circle';
                                        } elseif ($submission['status'] === 'rejected') {
                                            $statusClass = 'danger';
                                            $statusIcon = 'fa-times-circle';
                                        } else {
                                            $statusClass = 'warning';
                                            $statusIcon = 'fa-clock';
                                        }
                                    } elseif ($isOverdue) {
                                        $statusClass = 'danger';
                                        $statusIcon = 'fa-exclamation-circle';
                                    }
                                    ?>
                                    <div class="col-12 col-md-6 col-lg-4 requirement-col">
                                        <article class="requirement-card requirement-card-inner <?php echo $isOverdue ? 'overdue' : ($isDueSoon ? 'due-soon' : ''); ?>" aria-label="<?php echo htmlspecialchars($requirement['title']); ?> - <?php echo $submission ? ucfirst($submission['status']) : ($isOverdue ? 'Overdue' : 'Not Submitted'); ?>">
                                            <div class="requirement-card-header">
                                                <h3 class="requirement-title"><?php echo htmlspecialchars($requirement['title']); ?></h3>
                                                <span class="badge status-badge status-<?php echo $statusClass; ?>">
                                                    <i class="fas <?php echo $statusIcon; ?> me-1"></i>
                                                    <?php 
                                                    if ($submission) {
                                                        echo ucfirst($submission['status']);
                                                    } elseif ($isOverdue) {
                                                        echo 'Overdue';
                                                    } else {
                                                        echo 'Not Submitted';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            
                                            <div class="requirement-card-body">
                                                <p class="requirement-description">
                                                    <?php echo htmlspecialchars(substr($requirement['description'], 0, 120)) . (strlen($requirement['description']) > 120 ? '...' : ''); ?>
                                                </p>
                                                
                                                <div class="requirement-meta">
                                                    <span><i class="fas fa-file-alt me-1"></i><?php echo htmlspecialchars($requirement['file_types']); ?></span>
                                                    <span><i class="fas fa-weight-hanging me-1"></i><?php echo number_format(($requirement['max_file_size_normalized'] ?? $requirement['max_file_size'] ?? 5242880) / 1024 / 1024, 1); ?> MB</span>
                                                </div>
                                                
                                                <?php if ($requirement['deadline']): ?>
                                                    <div class="deadline-section">
                                                        <i class="fas fa-calendar-alt me-2"></i>
                                                        <span class="deadline-label">Deadline:</span>
                                                        <span class="deadline-date <?php echo $isOverdue ? 'text-danger' : ($isDueSoon ? 'text-warning' : ''); ?>">
                                                            <?php echo formatDate($requirement['deadline'], 'M j, Y'); ?>
                                                        </span>
                                                        <?php if (!$isOverdue && $daysLeft > 0): ?>
                                                            <small class="deadline-progress-text ms-2">
                                                                (<?php echo $daysLeft . ' day' . ($daysLeft > 1 ? 's' : '') . ' left'; ?>)
                                                            </small>
                                                        <?php elseif ($isOverdue): ?>
                                                            <small class="text-danger ms-2">(Passed)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($submission): ?>
                                                    <div class="submission-info">
                                                        <small><i class="fas fa-upload me-1"></i>Submitted: <?php echo formatDate($submission['submitted_at'], 'M j, Y'); ?></small>
                                                        <?php if ($attachment): ?>
                                                            <small><i class="fas fa-paperclip me-1"></i><a href="download_attachment.php?file=<?php echo urlencode($attachment['file_path']); ?>" class="attachment-link"><?php echo htmlspecialchars($attachment['original_filename']); ?></a></small>
                                                        <?php endif; ?>
                                                        <?php if ($submission['admin_notes']): ?>
                                                            <div class="admin-notes">
                                                                <strong>Admin Notes:</strong>
                                                                <p><?php echo htmlspecialchars($submission['admin_notes']); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="requirement-card-footer action-buttons">
                                                <?php if ($submission): ?>
                                                    <a href="submissions.php?view=<?php echo $submission['id']; ?>" class="btn btn-primary btn-action" title="View submission">
                                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                                        <span>View</span>
                                                    </a>
                                                    <?php if ($submission['status'] === 'rejected' && !$isOverdue): ?>
                                                        <button type="button" class="btn btn-warning btn-action submit-requirement-btn" 
                                                                data-requirement-id="<?php echo $requirement['id']; ?>" 
                                                                data-file-types="<?php echo htmlspecialchars($requirement['file_types']); ?>" 
                                                                data-max-size="<?php echo $requirement['max_file_size_normalized'] ?? $requirement['max_file_size'] ?? 5242880; ?>"
                                                                aria-label="Resubmit file for <?php echo htmlspecialchars($requirement['title']); ?>">
                                                            <i class="fas fa-redo" aria-hidden="true"></i>
                                                            <span>Resubmit</span>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (!$isOverdue): ?>
                                                        <button type="button" class="btn btn-primary btn-action submit-requirement-btn" 
                                                                data-requirement-id="<?php echo $requirement['id']; ?>" 
                                                                data-file-types="<?php echo htmlspecialchars($requirement['file_types']); ?>" 
                                                                data-max-size="<?php echo $requirement['max_file_size_normalized'] ?? $requirement['max_file_size'] ?? 5242880; ?>"
                                                                aria-label="Submit file for <?php echo htmlspecialchars($requirement['title']); ?>">
                                                            <i class="fas fa-upload" aria-hidden="true"></i>
                                                            <span>Submit</span>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-secondary btn-action attach-requirement-btn" 
                                                                data-requirement-id="<?php echo $requirement['id']; ?>" 
                                                                data-file-types="<?php echo htmlspecialchars($requirement['file_types']); ?>" 
                                                                data-max-size="<?php echo $requirement['max_file_size_normalized'] ?? $requirement['max_file_size'] ?? 5242880; ?>"
                                                                aria-label="<?php echo $attachment ? 'Re-attach' : 'Attach'; ?> file for <?php echo htmlspecialchars($requirement['title']); ?>">
                                                            <i class="fas fa-paperclip" aria-hidden="true"></i>
                                                            <span><?php echo $attachment ? 'Re-Attach' : 'Attach'; ?></span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-secondary btn-action" disabled aria-label="Deadline has passed">
                                                            <i class="fas fa-clock" aria-hidden="true"></i>
                                                            <span>Deadline Passed</span>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            </main>
        </div>
    </div>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <!-- Load requirements-specific script LAST -->
    <script src="<?php echo asset_url('js/requirements.js', true); ?>"></script>
    <script>
        // CRITICAL FIX: Clean up Bootstrap backdrops that might block buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Clean up any leftover modal backdrops when modals are closed
            function cleanupBackdrops() {
                const body = document.body;
                const backdrops = document.querySelectorAll('.modal-backdrop');
                
                // If body doesn't have modal-open class, remove all backdrops
                if (!body || !body.classList) return;
                if (!body.classList.contains('modal-open')) {
                    backdrops.forEach(function(backdrop) {
                        // Remove backdrop if it's not showing
                        if (!backdrop.classList.contains('show')) {
                            backdrop.remove();
                        } else {
                            // If it's showing but body doesn't have modal-open, hide it
                            backdrop.style.display = 'none';
                            backdrop.style.pointerEvents = 'none';
                            backdrop.style.zIndex = '-1';
                            backdrop.classList.remove('show');
                        }
                    });
                }
            }
            
            // Clean up on page load
            setTimeout(cleanupBackdrops, 100);
            
            // Watch for modal close events
            document.querySelectorAll('.modal').forEach(function(modal) {
                modal.addEventListener('hidden.bs.modal', function() {
                    setTimeout(function() {
                        cleanupBackdrops();
                        // Ensure body doesn't have modal-open class
                        if (document.body && document.body.classList) {
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                            document.body.style.paddingRight = '';
                        }
                    }, 50);
                });
            });
            
            // Also clean up periodically (in case Bootstrap doesn't fire events properly)
            setInterval(cleanupBackdrops, 500);
            
            // Watch for body class changes
            const bodyObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        if (document.body && document.body.classList && !document.body.classList.contains('modal-open')) {
                            cleanupBackdrops();
                        }
                    }
                });
            });
            
            bodyObserver.observe(document.body, {
                attributes: true,
                attributeFilter: ['class']
            });
            
            // CRITICAL: Ensure ALL requirement buttons are clickable with proper z-index
            function fixRequirementButtons() {
                document.querySelectorAll('.submit-requirement-btn, .attach-requirement-btn, .btn-action, .requirement-card-footer .btn, .requirement-card-footer button, .requirement-card-footer a').forEach(function(button) {
                    // Skip buttons inside open modals
                    if (button.closest('.modal.show')) {
                        return;
                    }
                    
                    // Skip if disabled
                    if (button.disabled) {
                        return;
                    }
                    
                    // Ensure button is fully clickable with high z-index
                    button.style.pointerEvents = 'auto';
                    button.style.touchAction = 'manipulation';
                    button.style.cursor = 'pointer';
                    button.style.position = 'relative';
                    button.style.zIndex = '1000';
                    
                    // Stop event propagation from parent to button
                    // Use capture phase to intercept before parent handlers
                    button.addEventListener('click', function(e) {
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                    }, true);
                    
                    button.addEventListener('touchstart', function(e) {
                        e.stopPropagation();
                    }, { passive: true, capture: true });
                    
                    button.addEventListener('touchend', function(e) {
                        e.stopPropagation();
                    }, { passive: true, capture: true });
                });
            }
            
            // Fix buttons on load
            setTimeout(fixRequirementButtons, 100);
            
            // Fix buttons when content changes
            const observer = new MutationObserver(function() {
                cleanupBackdrops();
                setTimeout(fixRequirementButtons, 50);
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });
        });
    </script>
    
    <!-- Upload & Attach Modals (moved to end of body to avoid stacking context issues) -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <div class="modal-content requirement-modal">
                <div class="modal-header requirement-modal-header">
                    <div class="modal-title-wrapper">
                        <i class="fas fa-upload modal-title-icon"></i>
                        <h5 class="modal-title" id="uploadModalLabel">Submit File</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="uploadModalForm" enctype="multipart/form-data">
                    <div class="modal-body requirement-modal-body">
                        <input type="hidden" name="action" value="submit">
                        <input type="hidden" name="requirement_id" id="modalRequirementId" value="">
                        <input type="hidden" name="csrf_token" id="modalCsrfToken" value="<?php echo $clientFormToken; ?>">
                        
                        <div class="file-upload-section">
                            <label for="modalFile" class="file-upload-label" id="uploadLabel">
                                <div class="file-upload-icon-wrapper">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <div class="file-upload-text">
                                    <span class="file-upload-title">Click to select or drag and drop</span>
                                    <span class="file-upload-subtitle">Choose a file from your device</span>
                                </div>
                            </label>
                            <input type="file" class="form-control file-input" id="modalFile" name="file">
                            <div class="file-upload-hint" id="modalFileHint">
                                <i class="fas fa-info-circle"></i>
                                <span>Allowed types: - | Max size: - MB</span>
                            </div>
                            <div class="file-preview" id="uploadFilePreview" style="display: none;">
                                <div class="file-preview-content">
                                    <div class="file-preview-icon">
                                        <i class="fas fa-file-check"></i>
                                    </div>
                                    <div class="file-preview-info">
                                        <div class="file-name"></div>
                                        <div class="file-size"></div>
                                    </div>
                                    <button type="button" class="btn-remove-file" onclick="clearFilePreview('upload')" title="Remove file">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="upload-progress" id="uploadProgress" style="display: none;">
                            <div class="progress-section">
                                <div class="progress-bar-container">
                                    <div class="progress-bar" id="uploadProgressBar"></div>
                                </div>
                                <div class="progress-text" id="uploadProgressText">Uploading...</div>
                            </div>
                        </div>
                        
                        <div class="confirmation-section">
                            <div class="form-check confirmation-checkbox">
                                <input class="form-check-input" type="checkbox" id="modalConfirm">
                                <label class="form-check-label" for="modalConfirm">
                                    <i class="fas fa-check-circle confirmation-icon"></i>
                                    <span class="confirmation-text">
                                        <strong>I confirm</strong> that the uploaded file is accurate and complete.
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="uploadError" class="alert alert-danger error-message" style="display:none;" role="alert">
                            <div class="error-content">
                                <i class="fas fa-exclamation-triangle error-icon"></i>
                                <div class="error-details">
                                    <strong class="error-title">Upload Error</strong>
                                    <span class="error-text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer requirement-modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-mobile-fixed="true" style="touch-action: manipulation;-webkit-tap-highlight-color: rgba(0, 51, 102, 0.2);cursor: pointer;pointer-events: auto;padding: 0;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="modalSubmitBtn" data-mobile-fixed="true" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto; padding: 0;">
                            <i class="fas fa-upload"></i> Submit File
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="attachModal" tabindex="-1" aria-labelledby="attachModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content requirement-modal">
                <div class="modal-header requirement-modal-header">
                    <div class="modal-title-wrapper">
                        <i class="fas fa-paperclip modal-title-icon"></i>
                        <h5 class="modal-title" id="attachModalLabel">Attach File to Requirement</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="attachModalForm" enctype="multipart/form-data">
                    <div class="modal-body requirement-modal-body">
                        <input type="hidden" name="action" value="attach">
                        <input type="hidden" name="requirement_id" id="attachModalRequirementId" value="">
                        <input type="hidden" name="csrf_token" id="attachModalCsrfToken" value="<?php echo $clientFormToken; ?>">
                        
                        <div class="file-upload-section">
                            <label for="attachModalFile" class="file-upload-label" id="attachLabel">
                                <div class="file-upload-icon-wrapper">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <div class="file-upload-text">
                                    <span class="file-upload-title">Click to select or drag and drop</span>
                                    <span class="file-upload-subtitle">Choose a file from your device</span>
                                </div>
                            </label>
                            <input type="file" class="form-control file-input" id="attachModalFile" name="file">
                            <div class="file-upload-hint" id="attachModalFileHint">
                                <i class="fas fa-info-circle"></i>
                                <span>Allowed types: - | Max size: - MB</span>
                            </div>
                            <div class="file-preview" id="attachFilePreview" style="display: none;">
                                <div class="file-preview-content">
                                    <div class="file-preview-icon">
                                        <i class="fas fa-file-check"></i>
                                    </div>
                                    <div class="file-preview-info">
                                        <div class="file-name"></div>
                                        <div class="file-size"></div>
                                    </div>
                                    <button type="button" class="btn-remove-file" onclick="clearFilePreview('attach')" title="Remove file">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="upload-progress" id="attachProgress" style="display: none;">
                            <div class="progress-section">
                                <div class="progress-bar-container">
                                    <div class="progress-bar" id="attachProgressBar"></div>
                                </div>
                                <div class="progress-text" id="attachProgressText">Uploading...</div>
                            </div>
                        </div>
                        
                        <div class="confirmation-section">
                            <div class="form-check confirmation-checkbox">
                                <input class="form-check-input" type="checkbox" id="attachModalConfirm">
                                <label class="form-check-label" for="attachModalConfirm">
                                    <i class="fas fa-check-circle confirmation-icon"></i>
                                    <span class="confirmation-text">
                                        <strong>I confirm</strong> that the attached file is accurate and complete.
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div id="attachError" class="alert alert-danger error-message" style="display:none;" role="alert">
                            <div class="error-content">
                                <i class="fas fa-exclamation-triangle error-icon"></i>
                                <div class="error-details">
                                    <strong class="error-title">Upload Error</strong>
                                    <span class="error-text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer requirement-modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-mobile-fixed="true" style="touch-action: manipulation;-webkit-tap-highlight-color: rgba(0, 51, 102, 0.2);cursor: pointer;pointer-events: auto;padding: 0;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="attachModalSubmitBtn">
                            <i class="fas fa-paperclip"></i> Attach File
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>









