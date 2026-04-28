<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/auth.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    if ($action === 'create') {
        $title = sanitizeInput($_POST['title']);
        $description = sanitizeInput($_POST['description']);
        $fileTypes = sanitizeInput($_POST['file_types']);
        // Admin provides max file size in MB via the form. Convert to bytes for storage.
        $maxFileSizeMb = (int)$_POST['max_file_size'];
        $maxFileSize = $maxFileSizeMb * 1024 * 1024;
        $deadline = $_POST['deadline'] ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedFaculty = isset($_POST['faculty_ids']) ? $_POST['faculty_ids'] : [];
        
        $db->beginTransaction();
        try {
            // Insert the requirement
            $stmt = $db->prepare("INSERT INTO requirements (title, description, file_types, max_file_size, deadline, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $fileTypes, $maxFileSize, $deadline, $isActive, $_SESSION['user_id']]);
            $requirementId = $db->lastInsertId();
            
            // Assign to selected faculty
            if (!empty($selectedFaculty)) {
                $assignStmt = $db->prepare("INSERT INTO faculty_requirements (requirement_id, faculty_id, assigned_by) VALUES (?, ?, ?)");
                foreach ($selectedFaculty as $facultyId) {
                    $assignStmt->execute([$requirementId, $facultyId, $_SESSION['user_id']]);
                }
            }
            
            $db->commit();
            
            // Log action
            logAction('REQUIREMENT_CREATE', "Created requirement: $title with " . count($selectedFaculty) . " assignments");
            
            if (!empty($selectedFaculty)) {
                $_SESSION['success'] = 'Requirement created and assigned successfully!';
                
                // Send email notifications and in-app notifications to selected faculty
                require_once '../includes/notifications.php';
                require_once '../includes/mailer.php';
                
                $notificationManager = getNotificationManager();
                $mailer = new Mailer();
                
                $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE id IN (" . str_repeat('?,', count($selectedFaculty) - 1) . "?)");
                $stmt->execute($selectedFaculty);
                $faculty = $stmt->fetchAll();
                
                foreach ($faculty as $member) {
                    // Send in-app notification
                    $notificationManager->notifyNewRequirement(
                        $member['id'],
                        $title,
                        $requirementId,
                        $deadline
                    );
                    
                    // Send email notification
                    $mailer->sendRequirementNotification(
                        $member['email'],
                        $member['first_name'] . ' ' . $member['last_name'],
                        $title,
                        $deadline ?: 'No deadline specified'
                    );
                }
            } else {
                $_SESSION['success'] = 'Requirement created successfully! (No faculty assigned yet)';
            }
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to create requirement: ' . $e->getMessage();
        }
        
        header('Location: requirements.php');
        exit();
        
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $title = sanitizeInput($_POST['title']);
        $description = sanitizeInput($_POST['description']);
        $fileTypes = sanitizeInput($_POST['file_types']);
        // Admin provides max file size in MB via the form. Convert to bytes for storage.
        $maxFileSizeMb = (int)$_POST['max_file_size'];
        $maxFileSize = $maxFileSizeMb * 1024 * 1024;
        $deadline = $_POST['deadline'] ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedFaculty = isset($_POST['faculty_ids']) ? $_POST['faculty_ids'] : [];
        
        $db->beginTransaction();
        try {
            // Get existing assignments to identify new faculty
            $stmt = $db->prepare("SELECT faculty_id FROM faculty_requirements WHERE requirement_id = ?");
            $stmt->execute([$id]);
            $existingAssignments = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Update requirement
            $stmt = $db->prepare("UPDATE requirements SET title = ?, description = ?, file_types = ?, max_file_size = ?, deadline = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$title, $description, $fileTypes, $maxFileSize, $deadline, $isActive, $id]);
            
            // Remove existing assignments
            $stmt = $db->prepare("DELETE FROM faculty_requirements WHERE requirement_id = ?");
            $stmt->execute([$id]);
            
            // Add new assignments
            $newlyAssignedFaculty = [];
            if (!empty($selectedFaculty)) {
                $assignStmt = $db->prepare("INSERT INTO faculty_requirements (requirement_id, faculty_id, assigned_by) VALUES (?, ?, ?)");
                foreach ($selectedFaculty as $facultyId) {
                    $assignStmt->execute([$id, $facultyId, $_SESSION['user_id']]);
                    
                    // Track newly assigned faculty (not in previous assignments)
                    if (!in_array($facultyId, $existingAssignments)) {
                        $newlyAssignedFaculty[] = $facultyId;
                    }
                }
            }
            
            $db->commit();
            
            // Log action
            logAction('REQUIREMENT_UPDATE', "Updated requirement: $title");
            
            $_SESSION['success'] = 'Requirement updated successfully!';
            
            // Send notifications only to newly assigned faculty
            if (!empty($newlyAssignedFaculty)) {
                require_once '../includes/notifications.php';
                require_once '../includes/mailer.php';
                
                $notificationManager = getNotificationManager();
                $mailer = new Mailer();
                
                $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE id IN (" . str_repeat('?,', count($newlyAssignedFaculty) - 1) . "?)");
                $stmt->execute($newlyAssignedFaculty);
                $faculty = $stmt->fetchAll();
                
                foreach ($faculty as $member) {
                    // Send in-app notification
                    $notificationManager->notifyNewRequirement(
                        $member['id'],
                        $title,
                        $id,
                        $deadline
                    );
                    
                    // Send email notification
                    $mailer->sendRequirementNotification(
                        $member['email'],
                        $member['first_name'] . ' ' . $member['last_name'],
                        $title,
                        $deadline ?: 'No deadline specified'
                    );
                }
            }
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to update requirement: ' . $e->getMessage();
        }
        
        header('Location: requirements.php');
        exit();
        
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        $reqStmt = $db->prepare("SELECT title FROM requirements WHERE id = ?");
        $reqStmt->execute([$id]);
        $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
        $reqTitle = $req ? $req['title'] : "ID:$id";
        
        $stmt = $db->prepare("DELETE FROM requirements WHERE id = ?");
        
        if ($stmt->execute([$id])) {
            logAction('REQUIREMENT_DELETE', "Deleted requirement: $reqTitle (ID: $id)");
            $_SESSION['success'] = 'Requirement deleted successfully!';
        } else {
            $_SESSION['error'] = 'Failed to delete requirement.';
        }
        
        header('Location: requirements.php');
        exit();
    }
}

// Filters: status (all|active|inactive), search (title/description)
$statusFilter = $_GET['status'] ?? 'all';
$searchFilter = trim($_GET['search'] ?? '');
$whereParts = ["1=1"];
$params = [];
if ($statusFilter === 'active') {
    $whereParts[] = "r.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $whereParts[] = "r.is_active = 0";
}
if ($searchFilter !== '') {
    $whereParts[] = "(r.title LIKE ? OR r.description LIKE ?)";
    $searchTerm = '%' . $searchFilter . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
$whereSql = implode(' AND ', $whereParts);

// Get requirements with pagination (10 per page)
$countSql = "SELECT COUNT(*) FROM requirements r JOIN users u ON r.created_by = u.id WHERE $whereSql";
$p = getPaginationParams($db, $countSql, $params, 10);

$stmt = $db->prepare("SELECT r.*, u.first_name, u.last_name FROM requirements r JOIN users u ON r.created_by = u.id WHERE $whereSql ORDER BY r.created_at DESC LIMIT {$p['limit']} OFFSET {$p['offset']}");
$stmt->execute($params);
$requirements = $stmt->fetchAll();

// Get requirement for editing
$editRequirement = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM requirements WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editRequirement = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Requirements Management', 'Manage requirements and assignments for faculty');
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
                    'Requirements Management',
                    '',
                    'fas fa-tasks',
                    [
                        
                    ],
                    '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRequirementModal"><i class="fas fa-plus me-1"></i>Create Requirement</button>'
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Requirements List -->
                <div class="card shadow-sm border-0 requirements-card">
                    <div class="card-header bg-white border-bottom py-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <h5 class="mb-0 d-flex align-items-center">
                                <i class="fas fa-list-check me-2 text-primary"></i>Requirements
                                <span class="badge bg-primary rounded-pill ms-2 px-2 py-1" id="totalCount"><?php echo number_format($p['total']); ?></span>
                            </h5>
                            <button type="button" class="btn btn-primary btn-sm d-md-none" data-bs-toggle="modal" data-bs-target="#createRequirementModal">
                                <i class="fas fa-plus me-1"></i>New
                            </button>
                        </div>
                        <!-- Filters -->
                        <form method="get" class="requirements-filters row g-2 align-items-end" role="search">
                            <input type="hidden" name="page" value="1">
                            <div class="col-12 col-md-4 col-lg-3">
                                <label for="filterSearch" class="form-label small text-muted mb-0">Search</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="search" class="form-control" id="filterSearch" name="search" placeholder="Title or description…" value="<?php echo htmlspecialchars($searchFilter); ?>" aria-label="Search requirements">
                                </div>
                            </div>
                            <div class="col-8 col-md-3 col-lg-2">
                                <label for="filterStatus" class="form-label small text-muted mb-0">Status</label>
                                <select class="form-select form-select-sm" id="filterStatus" name="status" aria-label="Filter by status">
                                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-4 col-md-2">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="fas fa-filter me-1"></i>Apply
                                </button>
                            </div>
                            <?php if ($searchFilter !== '' || $statusFilter !== 'all'): ?>
                            <div class="col-12 col-md-2">
                                <a href="requirements.php" class="btn btn-outline-secondary btn-sm">Clear filters</a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($requirements)): ?>
                            <div class="empty-state text-center py-5 px-4">
                                <div class="empty-icon mb-3">
                                    <i class="fas fa-clipboard-list fa-4x text-primary opacity-25"></i>
                                </div>
                                <h5 class="empty-title mb-2 fw-semibold"><?php echo ($searchFilter !== '' || $statusFilter !== 'all') ? 'No requirements match your filters' : 'No requirements yet'; ?></h5>
                                <p class="text-muted mb-4 mx-auto" style="max-width: 360px;">
                                    <?php if ($searchFilter !== '' || $statusFilter !== 'all'): ?>
                                        Try different search terms or status, or clear filters to see all.
                                    <?php else: ?>
                                        Create a requirement to assign to faculty—they’ll be notified and can submit documents by the deadline.
                                    <?php endif; ?>
                                </p>
                                <?php if ($searchFilter !== '' || $statusFilter !== 'all'): ?>
                                    <a href="requirements.php" class="btn btn-outline-primary"><i class="fas fa-times me-1"></i>Clear filters</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRequirementModal">
                                        <i class="fas fa-plus me-1"></i>Create first requirement
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4" style="width: 25%;">Title</th>
                                            <th class="d-none d-lg-table-cell" style="width: 20%;">Description</th>
                                            <th class="d-none d-md-table-cell text-center" style="width: 12%;">File Types</th>
                                            <th style="width: 15%;">Deadline</th>
                                            <th class="text-center" style="width: 10%;">Status</th>
                                            <th class="d-none d-xl-table-cell" style="width: 12%;">Created By</th>
                                            <th class="d-none d-xl-table-cell" style="width: 10%;">Created</th>
                                            <th class="text-end pe-4" style="width: 10%;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requirements as $requirement): 
                                            $isDueSoon = $requirement['deadline'] && strtotime($requirement['deadline']) <= strtotime('+7 days');
                                            $isOverdue = $requirement['deadline'] && strtotime($requirement['deadline']) < time();
                                        ?>
                                            <tr class="requirement-row">
                                                <td class="ps-4" data-label="Title">
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-dark mb-1"><?php echo htmlspecialchars($requirement['title']); ?></strong>
                                                        <div class="text-muted small d-lg-none">
                                                            <?php echo htmlspecialchars(substr($requirement['description'], 0, 60)) . (strlen($requirement['description']) > 60 ? '…' : ''); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="d-none d-lg-table-cell">
                                                    <span class="text-muted small"><?php echo htmlspecialchars(substr($requirement['description'], 0, 100)) . (strlen($requirement['description']) > 100 ? '…' : ''); ?></span>
                                                </td>
                                                <td class="d-none d-md-table-cell text-center">
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">
                                                        <i class="fas fa-file me-1"></i><?php echo htmlspecialchars($requirement['file_types']); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Deadline">
                                                    <?php if ($requirement['deadline']): ?>
                                                        <div class="d-flex align-items-center gap-1">
                                                            <i class="fas fa-calendar-alt text-muted small"></i>
                                                            <span class="<?php echo $isOverdue ? 'text-danger fw-semibold' : ($isDueSoon ? 'text-warning' : ''); ?>">
                                                                <?php echo formatDate($requirement['deadline'], 'M j, Y'); ?>
                                                            </span>
                                                        </div>
                                                        <?php if ($isOverdue): ?>
                                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 mt-1">
                                                                <i class="fas fa-exclamation-triangle me-1"></i>Overdue
                                                            </span>
                                                        <?php elseif ($isDueSoon): ?>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 mt-1">
                                                                <i class="fas fa-clock me-1"></i>Due Soon
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">
                                                            <i class="fas fa-infinity me-1"></i>No deadline
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center" data-label="Status">
                                                    <?php if ($requirement['is_active']): ?>
                                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1">
                                                            <i class="fas fa-check-circle me-1"></i>Active
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-1">
                                                            <i class="fas fa-pause-circle me-1"></i>Inactive
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-xl-table-cell">
                                                    <div class="d-flex align-items-center">
                                                        <span class="small"><?php echo htmlspecialchars($requirement['first_name'] . ' ' . $requirement['last_name']); ?></span>
                                                    </div>
                                                </td>
                                                <td class="d-none d-xl-table-cell">
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock me-1"></i><?php echo formatDate($requirement['created_at'], 'M j, Y'); ?>
                                                    </small>
                                                </td>
                                                <td class="text-end pe-4" data-label="Actions">
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="Requirement actions">
                                                        <button type="button" class="btn btn-outline-primary" 
                                                            onclick="editRequirement(<?php echo htmlspecialchars(json_encode($requirement, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                            title="Edit requirement"
                                                            data-bs-toggle="tooltip">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <a href="submissions.php?requirement_id=<?php echo $requirement['id']; ?>" 
                                                           class="btn btn-outline-info" 
                                                           title="View submissions"
                                                           data-bs-toggle="tooltip">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-outline-danger" 
                                                                onclick="deleteRequirement(<?php echo $requirement['id']; ?>)" 
                                                                title="Delete requirement"
                                                                data-bs-toggle="tooltip">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php
                            // Pagination controls for requirements
                            if (!empty($p) && $p['totalPages'] > 1) {
                                echo '<div class="card-footer bg-white border-top py-3">';
                                echo renderPagination($p['page'], $p['totalPages']);
                                echo '</div>';
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Create/Edit Requirement Modal -->
    <div class="modal fade" id="createRequirementModal" tabindex="-1" aria-labelledby="createRequirementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title d-flex align-items-center" id="modalTitle">
                        <i class="fas fa-plus-circle me-2"></i>Create New Requirement
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="requirementForm">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="create" id="formAction">
                        <input type="hidden" name="id" id="requirementId">
                        
                        <div class="row g-4">
                            <!-- Section: Basic information -->
                            <div class="col-12">
                                <h6 class="modal-section-title text-uppercase text-muted small fw-bold mb-3 d-flex align-items-center">
                                    <span class="modal-section-num me-2">1</span> Basic information
                                </h6>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="title" class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg" id="title" name="title" 
                                               placeholder="e.g. Annual Teaching Portfolio" required>
                                    </div>
                                    <div class="col-12">
                                        <label for="description" class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="description" name="description" rows="3" 
                                                  placeholder="What should faculty submit? Be specific so they know exactly what to upload." required></textarea>
                                        <div class="form-text">Clear instructions improve compliance and reduce back-and-forth.</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Assign to faculty -->
                            <div class="col-12">
                                <h6 class="modal-section-title text-uppercase text-muted small fw-bold mb-3 d-flex align-items-center">
                                    <span class="modal-section-num me-2">2</span> Assign to faculty
                                </h6>
                                <label class="form-label fw-semibold visually-hidden">Assign to faculty</label>
                                <div class="input-group mb-2">
                                    <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" class="form-control" id="searchFaculty" 
                                           placeholder="Search by name, email, or type &quot;faculty&quot; / &quot;staff&quot;">
                                    <button type="button" class="btn btn-outline-secondary" onclick="selectAllFaculty()" title="Select all on this page">
                                        <i class="fas fa-check-double"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="deselectAllFaculty()" title="Deselect all">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="border rounded-3 p-3 bg-light faculty-assign-box">
                                    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom flex-wrap gap-2">
                                        <small class="text-muted fw-semibold"><i class="fas fa-user-check me-1"></i>Who should complete this?</small>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-primary rounded-pill" id="selectedCount"><span id="countDisplay">0</span> selected</span>
                                            <select class="form-select form-select-sm" id="itemsPerPage" style="width: auto;" onchange="changeItemsPerPage()" aria-label="Items per page">
                                                <option value="5" selected>5 per page</option>
                                                <option value="10">10 per page</option>
                                                <option value="15">15 per page</option>
                                                <option value="20">20 per page</option>
                                                <option value="30">30 per page</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div id="facultyListContainer" class="faculty-list-scroll" style="max-height: 260px; overflow-y: auto;">
                                        <?php
                                        $stmt = $db->prepare("SELECT id, first_name, last_name, email, user_type FROM users WHERE user_type IN ('faculty', 'staff') AND is_verified = 1 AND is_active = 1 ORDER BY user_type, first_name, last_name");
                                        $stmt->execute();
                                        $facultyList = $stmt->fetchAll();
                                        foreach ($facultyList as $faculty) {
                                            $roleClass = $faculty['user_type'] === 'faculty' ? 'bg-primary bg-opacity-10 text-primary border-primary' : 'bg-success bg-opacity-10 text-success border-success';
                                            $roleIcon = $faculty['user_type'] === 'faculty' ? 'fa-chalkboard-teacher' : 'fa-user-tie';
                                            echo '<div class="form-check mb-2 p-2 rounded faculty-item faculty-checkbox-item" data-name="' . htmlspecialchars(strtolower($faculty['first_name'] . ' ' . $faculty['last_name'])) . '" data-email="' . htmlspecialchars(strtolower($faculty['email'])) . '" data-role="' . htmlspecialchars(strtolower($faculty['user_type'])) . '">';
                                            echo '<input class="form-check-input" type="checkbox" name="faculty_ids[]" value="' . $faculty['id'] . '" id="faculty_' . $faculty['id'] . '" onchange="updateSelectedCount()">';
                                            echo '<label class="form-check-label w-100 d-flex align-items-center cursor-pointer" for="faculty_' . $faculty['id'] . '">';
                                            echo '<div class="avatar-circle-sm bg-primary bg-opacity-10 text-primary me-2">';
                                            echo '<i class="fas ' . $roleIcon . '"></i>';
                                            echo '</div>';
                                            echo '<div class="flex-grow-1">';
                                            echo '<div class="fw-semibold">' . htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) . '</div>';
                                            echo '<small class="text-muted">' . htmlspecialchars($faculty['email']) . '</small>';
                                            echo '</div>';
                                            echo '<span class="badge ' . $roleClass . ' border px-2 py-1 ms-2">' . htmlspecialchars(ucfirst($faculty['user_type'])) . '</span>';
                                            echo '</label>';
                                            echo '</div>';
                                        }
                                        ?>
                                    </div>
                                    <div id="facultyPagination" class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top">
                                        <small class="text-muted" id="paginationInfo">Showing 0-0 of 0</small>
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Faculty list pagination">
                                            <button type="button" class="btn btn-outline-secondary" id="prevPageBtn" onclick="changePage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" id="nextPageBtn" onclick="changePage(1)" disabled>
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Upload rules -->
                            <div class="col-12">
                                <h6 class="modal-section-title text-uppercase text-muted small fw-bold mb-3 d-flex align-items-center">
                                    <span class="modal-section-num me-2">3</span> Upload rules
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="file_types" class="form-label fw-semibold">Allowed file types <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="file_types" name="file_types" 
                                                   placeholder="e.g. pdf, doc, docx, jpg, png" required>
                                            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Quick presets">
                                                Presets
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="#" onclick="setFileTypes('pdf'); return false;"><i class="fas fa-file-pdf me-2 text-danger"></i>PDF only</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setFileTypes('pdf, doc, docx'); return false;"><i class="fas fa-file-word me-2 text-primary"></i>Documents (PDF, DOC, DOCX)</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setFileTypes('jpg, jpeg, png'); return false;"><i class="fas fa-file-image me-2 text-success"></i>Images (JPG, PNG)</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setFileTypes('pdf, jpg, jpeg, png'); return false;"><i class="fas fa-file-alt me-2 text-info"></i>PDF + images</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setFileTypes('pdf, doc, docx, jpg, jpeg, png'); return false;"><i class="fas fa-file-archive me-2 text-warning"></i>All common types</a></li>
                                            </ul>
                                        </div>
                                        <div class="form-text">Comma-separated; use Presets for quick choices.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="max_file_size" class="form-label fw-semibold">Max file size (MB) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="max_file_size" name="max_file_size" 
                                                   value="5" min="1" max="50" required aria-describedby="maxSizeHelp">
                                            <span class="input-group-text">MB</span>
                                        </div>
                                        <div class="form-text" id="maxSizeHelp">1–50 MB per file.</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Schedule & status -->
                            <div class="col-12">
                                <h6 class="modal-section-title text-uppercase text-muted small fw-bold mb-3 d-flex align-items-center">
                                    <span class="modal-section-num me-2">4</span> Schedule &amp; status
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="deadline" class="form-label fw-semibold">Deadline</label>
                                        <input type="date" class="form-control" id="deadline" name="deadline" aria-describedby="deadlineHelp">
                                        <div class="form-text" id="deadlineHelp">Optional. Leave blank for no due date.</div>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end pb-2">
                                        <div class="form-check form-switch form-check-lg w-100">
                                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                            <label class="form-check-label fw-semibold" for="is_active">
                                                Active — visible to assigned faculty
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 px-4 py-3">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-check me-1"></i>Create Requirement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title d-flex align-items-center text-danger" id="deleteModalLabel">
                        <span class="delete-modal-icon me-2"><i class="fas fa-trash-alt"></i></span>Delete requirement?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3 pb-2">
                    <p class="mb-2">This requirement and all submissions for it will be permanently removed. This cannot be undone.</p>
                    <div class="alert alert-light border border-warning text-warning mb-0 py-2 small">
                        <i class="fas fa-exclamation-triangle me-2"></i>All related faculty submissions will be deleted.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <style>
        /* Requirements page — UI/UX */
        .requirements-card .card-header { border-bottom: 1px solid var(--border, #e2e8f0); }
        .requirements-filters .form-label { font-size: 0.8rem; }
        .requirement-row { transition: background-color 0.15s ease; }
        .requirement-row:hover { background-color: rgba(0, 51, 102, 0.04); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.875rem; }
        .avatar-circle-sm { width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; }
        .avatar-circle-lg { width: 80px; height: 80px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
        .faculty-checkbox-item { transition: background-color 0.2s ease, border-radius 0.2s ease; cursor: pointer; }
        .faculty-checkbox-item:hover { background-color: rgba(0, 51, 102, 0.06); border-radius: 8px; }
        .faculty-checkbox-item input[type="checkbox"]:checked + label { background-color: rgba(0, 51, 102, 0.08); border-radius: 8px; }
        .cursor-pointer { cursor: pointer; }
        .empty-state { min-height: 320px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .empty-icon { opacity: 0.35; }
        .table th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; color: #64748b; }
        .table td { vertical-align: middle; }
        .btn-group-sm .btn { transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .btn-group-sm .btn:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .form-control:focus, .form-select:focus { border-color: var(--primary, #003366); box-shadow: 0 0 0 0.2rem rgba(0, 51, 102, 0.2); }

        /* Create/Edit modal sections */
        .modal-section-title { letter-spacing: 0.06em; }
        .modal-section-num { width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--primary, #003366); color: #fff; font-size: 0.7rem; display: inline-flex; align-items: center; justify-content: center; }
        .faculty-assign-box { border-color: rgba(0, 51, 102, 0.12); }
        .faculty-list-scroll { -webkit-overflow-scrolling: touch; }
        .faculty-list-scroll::-webkit-scrollbar { width: 6px; }
        .faculty-list-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .faculty-list-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        #createRequirementModal .modal-dialog { max-height: 90vh; margin: 1rem auto; }
        #createRequirementModal .modal-content { border-radius: 0.75rem; display: flex; flex-direction: column; max-height: 90vh; }
        #createRequirementModal .modal-header { border-radius: 0.75rem 0.75rem 0 0; flex-shrink: 0; }
        #createRequirementModal .modal-body { overflow-y: auto; overflow-x: hidden; flex: 1 1 auto; min-height: 0; max-height: calc(90vh - 140px); }
        #createRequirementModal .modal-body::-webkit-scrollbar { width: 8px; }
        #createRequirementModal .modal-body::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        #createRequirementModal .modal-body::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 4px; }
        #createRequirementModal .modal-footer { flex-shrink: 0; border-top: 1px solid #e2e8f0; }

        /* Delete modal */
        .delete-modal-icon { width: 2.25rem; height: 2.25rem; border-radius: 50%; background: rgba(220, 38, 38, 0.12); color: #dc2626; display: inline-flex; align-items: center; justify-content: center; }
    </style>
    <script>
        // Pagination variables
        let currentPage = 1;
        let itemsPerPage = 5;
        
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize pagination
            renderPagination();
            // Update selected count on page load
            updateSelectedCount();
        });

        async function editRequirement(requirement) {
            const modalTitle = document.getElementById('modalTitle');
            modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>Edit Requirement';
            document.getElementById('formAction').value = 'update';
            document.getElementById('requirementId').value = requirement.id;
            document.getElementById('title').value = requirement.title;
            document.getElementById('description').value = requirement.description;
            document.getElementById('file_types').value = requirement.file_types;
            document.getElementById('max_file_size').value = Math.round(requirement.max_file_size / 1024 / 1024);
            document.getElementById('deadline').value = requirement.deadline ? requirement.deadline.split(' ')[0] : '';
            document.getElementById('is_active').checked = requirement.is_active == 1;
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Update Requirement';
            
            // Get assigned faculty
            try {
                const response = await fetch(`get_assigned_faculty.php?requirement_id=${requirement.id}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const assignedFaculty = await response.json();
                
                // Clear all checkboxes first
                document.querySelectorAll('input[name="faculty_ids[]"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
                
                // Check boxes for assigned faculty
                assignedFaculty.forEach(facultyId => {
                    const checkbox = document.querySelector(`input[name="faculty_ids[]"][value="${facultyId}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
                
                updateSelectedCount();
            } catch (error) {
                console.error('Error fetching assigned faculty:', error);
                // Show error message to user
                const container = document.getElementById('facultyListContainer');
                let errorMsg = document.getElementById('errorMsg');
                if (!errorMsg) {
                    errorMsg = document.createElement('div');
                    errorMsg.id = 'errorMsg';
                    errorMsg.className = 'alert alert-warning mb-2';
                    errorMsg.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Could not load assigned faculty. Please select manually.';
                    container.insertBefore(errorMsg, container.firstChild);
                }
            }
            
            // Reset pagination and search
            currentPage = 1;
            document.getElementById('searchFaculty').value = '';
            filterFacultyList();
            
            new bootstrap.Modal(document.getElementById('createRequirementModal')).show();
        }
        
        function deleteRequirement(id) {
            document.getElementById('deleteId').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Initialize pagination when modal is shown
        document.getElementById('createRequirementModal').addEventListener('shown.bs.modal', function() {
            currentPage = 1;
            itemsPerPage = parseInt(document.getElementById('itemsPerPage').value) || 5;
            filterFacultyList();
        });
        
        // Reset form when modal is hidden
        document.getElementById('createRequirementModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('requirementForm').reset();
            const modalTitle = document.getElementById('modalTitle');
            modalTitle.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Create New Requirement';
            document.getElementById('formAction').value = 'create';
            document.getElementById('requirementId').value = '';
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>Create Requirement';
            document.getElementById('is_active').checked = true;
            // Clear search filter and reset pagination
            document.getElementById('searchFaculty').value = '';
            currentPage = 1;
            itemsPerPage = 5;
            document.getElementById('itemsPerPage').value = '5';
            filterFacultyList();
            updateSelectedCount();
            
            // Remove any error messages
            const errorMsg = document.getElementById('errorMsg');
            if (errorMsg) errorMsg.remove();
        });
        
        // Search/Filter Faculty List
        document.getElementById('searchFaculty').addEventListener('input', function() {
            currentPage = 1; // Reset to first page when searching
            filterFacultyList();
        });
        
        function filterFacultyList() {
            const searchTerm = document.getElementById('searchFaculty').value.toLowerCase().trim();
            const facultyItems = document.querySelectorAll('.faculty-item');
            let visibleItems = [];
            
            facultyItems.forEach(item => {
                const name = item.getAttribute('data-name');
                const email = item.getAttribute('data-email');
                const role = item.getAttribute('data-role');
                
                // Check if search term matches name, email, or role
                const matches = name.includes(searchTerm) || 
                               email.includes(searchTerm) || 
                               role.includes(searchTerm);
                
                if (matches) {
                    visibleItems.push(item);
                }
            });
            
            // Show message if no results found
            const container = document.getElementById('facultyListContainer');
            let noResultsMsg = document.getElementById('noResultsMsg');
            
            if (visibleItems.length === 0 && searchTerm) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.id = 'noResultsMsg';
                    noResultsMsg.className = 'text-center text-muted py-4';
                    noResultsMsg.innerHTML = '<i class="fas fa-search fa-2x mb-2 d-block opacity-50"></i><div>No faculty or staff members found matching your search.</div>';
                    container.appendChild(noResultsMsg);
                }
            } else {
                if (noResultsMsg) {
                    noResultsMsg.remove();
                }
            }
            
            // Render pagination with filtered items
            renderPagination(visibleItems);
        }
        
        function renderPagination(filteredItems = null) {
            const facultyItems = filteredItems || Array.from(document.querySelectorAll('.faculty-item'));
            const totalItems = facultyItems.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            // Ensure current page is valid
            if (currentPage > totalPages && totalPages > 0) {
                currentPage = totalPages;
            }
            if (currentPage < 1) {
                currentPage = 1;
            }
            
            // Calculate start and end indices
            const startIndex = totalItems > 0 ? (currentPage - 1) * itemsPerPage : 0;
            const endIndex = Math.min(startIndex + itemsPerPage, totalItems);
            
            // Hide all items first
            document.querySelectorAll('.faculty-item').forEach(item => {
                item.style.display = 'none';
            });
            
            // Show items for current page
            for (let i = startIndex; i < endIndex; i++) {
                if (facultyItems[i]) {
                    facultyItems[i].style.display = 'block';
                }
            }
            
            // Update pagination info
            const paginationInfo = document.getElementById('paginationInfo');
            if (paginationInfo) {
                if (totalItems === 0) {
                    paginationInfo.textContent = 'No items to display';
                } else {
                    paginationInfo.textContent = `Showing ${startIndex + 1}-${endIndex} of ${totalItems}`;
                }
            }
            
            // Update pagination buttons
            const prevPageBtn = document.getElementById('prevPageBtn');
            const nextPageBtn = document.getElementById('nextPageBtn');
            
            if (prevPageBtn) {
                prevPageBtn.disabled = currentPage <= 1;
            }
            if (nextPageBtn) {
                nextPageBtn.disabled = currentPage >= totalPages || totalPages === 0;
            }
        }
        
        function changePage(direction) {
            const facultyItems = Array.from(document.querySelectorAll('.faculty-item'));
            const searchTerm = document.getElementById('searchFaculty').value.toLowerCase().trim();
            
            // Filter items based on search
            let visibleItems = [];
            if (searchTerm) {
                facultyItems.forEach(item => {
                    const name = item.getAttribute('data-name');
                    const email = item.getAttribute('data-email');
                    const role = item.getAttribute('data-role');
                    const matches = name.includes(searchTerm) || 
                                   email.includes(searchTerm) || 
                                   role.includes(searchTerm);
                    if (matches) {
                        visibleItems.push(item);
                    }
                });
            } else {
                visibleItems = facultyItems;
            }
            
            const totalPages = Math.ceil(visibleItems.length / itemsPerPage);
            currentPage += direction;
            
            if (currentPage < 1) {
                currentPage = 1;
            } else if (currentPage > totalPages && totalPages > 0) {
                currentPage = totalPages;
            }
            
            renderPagination(visibleItems.length > 0 ? visibleItems : null);
        }
        
        function changeItemsPerPage() {
            const select = document.getElementById('itemsPerPage');
            itemsPerPage = parseInt(select.value);
            currentPage = 1; // Reset to first page
            filterFacultyList();
        }
        
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('input[name="faculty_ids[]"]:checked');
            const count = checkboxes.length;
            const countDisplay = document.getElementById('countDisplay');
            const selectedCount = document.getElementById('selectedCount');
            if (countDisplay) countDisplay.textContent = count;
            if (selectedCount) {
                selectedCount.className = count > 0 ? 'badge bg-primary rounded-pill' : 'badge bg-secondary bg-opacity-50 rounded-pill';
            }
        }
        
        function selectAllFaculty() {
            const visibleItems = document.querySelectorAll('.faculty-item[style*="block"], .faculty-item:not([style*="none"])');
            visibleItems.forEach(item => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = true;
                }
            });
            updateSelectedCount();
        }
        
        function deselectAllFaculty() {
            document.querySelectorAll('input[name="faculty_ids[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        }
        
        // Update count when checkboxes change
        document.addEventListener('change', function(e) {
            if (e.target.matches('input[name="faculty_ids[]"]')) {
                updateSelectedCount();
            }
        });
        
        // File type presets
        function setFileTypes(types) {
            document.getElementById('file_types').value = types;
            document.getElementById('file_types').focus();
        }
    </script>
</body>
</html>


