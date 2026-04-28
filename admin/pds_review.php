<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Handle POST requests for approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pdsId = (int)($_POST['pds_id'] ?? 0);
    $adminNotes = sanitizeInput($_POST['admin_notes'] ?? '');
    
    // Validate CSRF token
    if (!validateFormToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid form submission. Please try again.';
        header('Location: pds_review.php' . ($pdsId ? '?id=' . $pdsId : ''));
        exit();
    }
    
    if (!$pdsId) {
        $_SESSION['error'] = 'Invalid PDS ID';
        header('Location: pds_review.php');
        exit();
    }
    
    try {
        // Get PDS details for notification
        $stmt = $db->prepare("
            SELECT p.*, u.first_name, u.last_name, u.email
            FROM faculty_pds p
            JOIN users u ON p.faculty_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$pdsId]);
        $pdsInfo = $stmt->fetch();
        
        if (!$pdsInfo) {
            $_SESSION['error'] = 'PDS not found';
            header('Location: pds_review.php');
            exit();
        }
        
        // Only allow approval/rejection of submitted PDS
        if (($pdsInfo['status'] ?? '') !== 'submitted') {
            $_SESSION['error'] = 'This PDS cannot be approved or rejected. Only submitted PDS can be reviewed. (Current status: ' . htmlspecialchars($pdsInfo['status'] ?? 'unknown') . ')';
            header('Location: pds_review.php?id=' . $pdsId);
            exit();
        }
        
        if ($action === 'approve') {
            // Update PDS status to approved
            $stmt = $db->prepare("
                UPDATE faculty_pds 
                SET status = 'approved', 
                    reviewed_by = ?, 
                    reviewed_at = NOW(), 
                    admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $adminNotes, $pdsId]);
            
            logAction('PDS_APPROVED', "Approved PDS ID: $pdsId");
            
            // In-app notification (always shown; email may be disabled)
            try {
                require_once '../includes/notifications.php';
                $notificationManager = getNotificationManager();
                $notificationManager->notifyPDSStatus($pdsInfo['faculty_id'], 'approved');
            } catch (Exception $notifEx) {
                error_log("PDS approval: Failed to create in-app notification for faculty {$pdsInfo['faculty_id']}: " . $notifEx->getMessage());
            }
            
            // Send email notification
            try {
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                $mailer->sendPDSStatusNotification(
                    $pdsInfo['email'],
                    $pdsInfo['first_name'] . ' ' . $pdsInfo['last_name'],
                    'approved',
                    $adminNotes
                );
            } catch (Exception $mailEx) {
                error_log("PDS approval: Failed to send email to {$pdsInfo['email']}: " . $mailEx->getMessage());
            }
            
            $_SESSION['success'] = 'PDS approved successfully';
        } elseif ($action === 'reject') {
            // Require admin notes for rejection
            if (empty(trim($adminNotes))) {
                $_SESSION['error'] = 'Admin notes are required when rejecting a PDS';
                header('Location: pds_review.php?id=' . $pdsId);
                exit();
            }
            
            // Update PDS status to rejected
            $stmt = $db->prepare("
                UPDATE faculty_pds 
                SET status = 'rejected', 
                    reviewed_by = ?, 
                    reviewed_at = NOW(), 
                    admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $adminNotes, $pdsId]);
            
            logAction('PDS_REJECTED', "Rejected PDS ID: $pdsId");
            
            // In-app notification (always shown; email may be disabled)
            try {
                require_once '../includes/notifications.php';
                $notificationManager = getNotificationManager();
                $notificationManager->notifyPDSStatus($pdsInfo['faculty_id'], 'rejected');
            } catch (Exception $notifEx) {
                error_log("PDS rejection: Failed to create in-app notification for faculty {$pdsInfo['faculty_id']}: " . $notifEx->getMessage());
            }
            
            // Send email notification
            try {
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                $mailer->sendPDSStatusNotification(
                    $pdsInfo['email'],
                    $pdsInfo['first_name'] . ' ' . $pdsInfo['last_name'],
                    'rejected',
                    $adminNotes
                );
            } catch (Exception $mailEx) {
                error_log("PDS rejection: Failed to send email to {$pdsInfo['email']}: " . $mailEx->getMessage());
            }
            
            $_SESSION['success'] = 'PDS rejected. Faculty has been notified.';
        } else {
            $_SESSION['error'] = 'Invalid action';
        }
        
        header('Location: pds_review.php');
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error processing request: ' . $e->getMessage();
        header('Location: pds_review.php?id=' . $pdsId);
        exit();
    }
}

$action = $_GET['action'] ?? 'list';
$pdsId = $_GET['id'] ?? null;
$message = '';


// Get PDS submissions with filters
// By default, only show submitted PDSs (not drafts)
$whereClause = "p.status = 'submitted'";
$params = [];

$statusFilter = $_GET['status'] ?? '';
if ($statusFilter) {
    // If admin explicitly filters by status, override the default
    if ($statusFilter === 'all') {
        // Show all statuses
        $whereClause = "1=1";
    } else {
        // Show specific status
        $whereClause = "p.status = ?";
        $params[] = $statusFilter;
    }
}

$search = $_GET['search'] ?? '';
if ($search) {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Sort: name, email, status, submitted (default)
$sortCol = $_GET['sort'] ?? 'submitted';
$allowedSort = ['name' => 1, 'email' => 1, 'status' => 1, 'submitted' => 1];
if (!isset($allowedSort[$sortCol])) {
    $sortCol = 'submitted';
}
$orderDir = strtoupper($_GET['order'] ?? 'desc');
$orderDir = ($orderDir === 'ASC' || $orderDir === 'DESC') ? $orderDir : 'DESC';
$orderByMap = [
    'name'      => 'u.first_name ' . $orderDir . ', u.last_name ' . $orderDir,
    'email'     => 'u.email ' . $orderDir,
    'status'    => 'p.status ' . $orderDir,
    'submitted' => 'p.submitted_at ' . $orderDir,
];
$orderByClause = $orderByMap[$sortCol];

// Only show the latest PDS per faculty (one submission per employee)
$statusForSubquery = $statusFilter === 'all' ? '1=1' : ($statusFilter ? 'p2.status = ?' : "p2.status = 'submitted'");
$subqueryParams = $statusFilter && $statusFilter !== 'all' ? [$statusFilter] : [];
$latestSubquery = "SELECT p2.faculty_id, MAX(p2.id) as latest_id FROM faculty_pds p2 WHERE $statusForSubquery GROUP BY p2.faculty_id";

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

// Get total count for pagination (one per faculty)
$countSql = "SELECT COUNT(*) FROM faculty_pds p 
        JOIN users u ON p.faculty_id = u.id 
        INNER JOIN ($latestSubquery) latest ON p.faculty_id = latest.faculty_id AND p.id = latest.latest_id 
        WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute(array_merge($subqueryParams, $params));
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;
$page = min($page, max(1, $totalPages));
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, u.first_name, u.last_name, u.email, u.created_at as user_created
        FROM faculty_pds p
        JOIN users u ON p.faculty_id = u.id
        INNER JOIN ($latestSubquery) latest ON p.faculty_id = latest.faculty_id AND p.id = latest.latest_id
        WHERE $whereClause
        ORDER BY $orderByClause
        LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

$stmt = $db->prepare($sql);
$stmt->execute(array_merge($subqueryParams, $params));
$pdsSubmissions = $stmt->fetchAll();

function getPaginationParams($statusFilter, $search, $sort = 'submitted', $order = 'desc') {
    $q = [];
    if ($statusFilter !== '') $q['status'] = $statusFilter;
    if ($search !== '') $q['search'] = $search;
    if ($sort !== 'submitted' || $order !== 'desc') {
        $q['sort'] = $sort;
        $q['order'] = $order;
    }
    return $q;
}

function getPaginationHtml($currentPage, $totalPages, $totalRows, $perPage, $statusFilter, $search, $sort = 'submitted', $order = 'desc') {
    if ($totalPages <= 1 && $totalRows <= $perPage) return '';
    $base = getPaginationParams($statusFilter, $search, $sort, $order);
    $query = function ($page) use ($base) {
        $b = $base;
        if ($page > 1) $b['page'] = $page;
        return 'pds_review.php' . (count($b) ? '?' . http_build_query($b) : '');
    };
    $start = ($currentPage - 1) * $perPage + 1;
    $end = min($currentPage * $perPage, $totalRows);
    $out = '<nav class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3" aria-label="PDS pagination">';
    $out .= '<div class="text-muted small">Showing ' . $start . '–' . $end . ' of ' . $totalRows . '</div>';
    $out .= '<ul class="pagination pagination-sm mb-0">';
    $out .= '<li class="page-item' . ($currentPage <= 1 ? ' disabled' : '') . '">';
    $out .= $currentPage <= 1 ? '<span class="page-link">Previous</span>' : '<a class="page-link" href="' . htmlspecialchars($query($currentPage - 1)) . '">Previous</a>';
    $out .= '</li>';
    $maxLinks = 5;
    $from = max(1, $currentPage - (int)floor($maxLinks / 2));
    $to = min($totalPages, $from + $maxLinks - 1);
    $from = max(1, $to - $maxLinks + 1);
    for ($i = $from; $i <= $to; $i++) {
        $out .= '<li class="page-item' . ($i === $currentPage ? ' active' : '') . '">';
        $out .= $i === $currentPage ? '<span class="page-link">' . $i . '</span>' : '<a class="page-link" href="' . htmlspecialchars($query($i)) . '">' . $i . '</a>';
        $out .= '</li>';
    }
    $out .= '<li class="page-item' . ($currentPage >= $totalPages ? ' disabled' : '') . '">';
    $out .= $currentPage >= $totalPages ? '<span class="page-link">Next</span>' : '<a class="page-link" href="' . htmlspecialchars($query($currentPage + 1)) . '">Next</a>';
    $out .= '</li></ul></nav>';
    return $out;
}

function getPdsTableContent($pdsSubmissions, $statusFilter, $search, $sort = 'submitted', $order = 'desc') {
    if (empty($pdsSubmissions)) {
        $clearLink = '<a href="pds_review.php" class="text-primary">Clear filters</a>';
        $msg = ($statusFilter || $search) ? "No PDS submissions match your current filters. {$clearLink} to see all submissions." : 'There are no PDS submissions in the system yet.';
        return '<div class="empty-state-enhanced">' .
            '<div class="empty-state-icon"><i class="fas fa-file-contract"></i></div>' .
            '<h5 class="empty-state-title">No PDS Submissions Found</h5>' .
            '<p class="empty-state-text">' . $msg . '</p></div>';
    }
    $sortIcon = function ($col) use ($sort, $order) {
        if ($col !== $sort) return 'fa-sort';
        return $order === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
    };
    $out = '<div class="table-responsive-enhanced"><table class="table table-enhanced" data-current-sort="' . htmlspecialchars($sort) . '" data-current-order="' . htmlspecialchars($order) . '"><thead><tr>' .
        '<th class="sortable" data-sort="name"><i class="fas fa-user me-1"></i>Faculty <i class="fas ' . $sortIcon('name') . ' ms-1"></i></th>' .
        '<th class="sortable" data-sort="email"><i class="fas fa-envelope me-1"></i>Email <i class="fas ' . $sortIcon('email') . ' ms-1"></i></th>' .
        '<th class="sortable" data-sort="status"><i class="fas fa-info-circle me-1"></i>Status <i class="fas ' . $sortIcon('status') . ' ms-1"></i></th>' .
        '<th class="sortable" data-sort="submitted"><i class="fas fa-calendar me-1"></i>Submitted <i class="fas ' . $sortIcon('submitted') . ' ms-1"></i></th>' .
        '<th class="text-end"><i class="fas fa-cog me-1"></i>Actions</th></tr></thead><tbody>';
    foreach ($pdsSubmissions as $pds) {
        $statusClass = match($pds['status']) { 'submitted' => 'status-submitted', 'approved' => 'status-approved', 'rejected' => 'status-rejected', 'draft' => 'status-draft', default => 'status-submitted' };
        $statusIcon = match($pds['status']) { 'submitted' => 'fa-clock', 'approved' => 'fa-check-circle', 'rejected' => 'fa-times-circle', 'draft' => 'fa-edit', default => 'fa-clock' };
        $initials = strtoupper(substr($pds['first_name'], 0, 1) . substr($pds['last_name'], 0, 1));
        $name = htmlspecialchars($pds['first_name'] . ' ' . $pds['last_name']);
        $email = htmlspecialchars($pds['email']);
        $statusLabel = ucfirst($pds['status']);
        $submittedDate = formatDate($pds['submitted_at'], 'M j, Y');
        $submittedTime = formatDate($pds['submitted_at'], 'g:i A');
        $out .= '<tr class="table-row-enhanced" data-status="' . htmlspecialchars($pds['status']) . '">' .
            '<td><div class="d-flex align-items-center"><div class="avatar-circle me-2">' . $initials . '</div><div><strong class="faculty-name">' . $name . '</strong></div></div></td>' .
            '<td><a href="mailto:' . $email . '" class="email-link"><i class="fas fa-envelope me-1"></i>' . $email . '</a></td>' .
            '<td><span class="badge status-badge ' . $statusClass . '"><i class="fas ' . $statusIcon . ' me-1"></i>' . $statusLabel . '</span></td>' .
            '<td><div class="date-info"><i class="fas fa-calendar-alt me-1 text-muted"></i><span>' . $submittedDate . '</span><small class="text-muted d-block">' . $submittedTime . '</small></div></td>' .
            '<td><div class="action-buttons"><button type="button" class="btn btn-sm btn-primary btn-action" onclick="reviewPDS(' . (int)$pds['id'] . ')" title="View Details"><i class="fas fa-eye"></i><span class="d-none d-md-inline ms-1">View</span></button></div></td></tr>';
    }
    return $out . '</tbody></table></div>';
}

// AJAX: return table HTML, total count, and pagination
if (!empty($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'count' => $totalRows,
        'html'  => getPdsTableContent($pdsSubmissions, $statusFilter, $search, $sortCol, $orderDir),
        'pagination_html' => getPaginationHtml($page, $totalPages, $totalRows, $perPage, $statusFilter, $search, $sortCol, $orderDir)
    ]);
    exit;
}

// Get specific PDS for review
$reviewPDS = null;
if ($pdsId) {
    $stmt = $db->prepare("
        SELECT p.*, u.first_name, u.last_name, u.email, u.created_at as user_created
        FROM faculty_pds p
        JOIN users u ON p.faculty_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$pdsId]);
    $reviewPDS = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('PDS Review', 'Review and manage Personal Data Sheet submissions');
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
                    'PDS Review',
                    '',
                    'fas fa-file-contract',
                    [
                 
                    ],
                    '<button type="button" class="btn btn-primary btn-sm" onclick="exportPDS()"><i class="fas fa-download me-1"></i>Export</button>'
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Enhanced Statistics -->
                

                <!-- Filters moved into PDS List card (combined) -->

                <!-- Enhanced PDS List -->
                <div class="card enhanced-card">
                    <div class="card-header enhanced-header">
                        <div class="d-flex justify-content-between align-items-center w-100">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>
                                <span>PDS Submissions</span>
                                <span class="badge bg-primary ms-2" id="pdsCountBadge"><?php echo $totalRows; ?></span>
                            </h5>
                            <div class="header-actions">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportPDS()" title="Export to CSV">
                                    <i class="fas fa-download me-1"></i>Export
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="refreshPage()" title="Refresh">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Enhanced Filters -->
                        <div class="filter-section mb-4">
                            <form method="GET" id="filterForm" class="row g-3">
                                <input type="hidden" name="sort" id="filterSort" value="<?php echo htmlspecialchars($sortCol); ?>">
                                <input type="hidden" name="order" id="filterOrder" value="<?php echo htmlspecialchars($orderDir); ?>">
                                <div class="col-md-3 col-sm-6">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-filter me-1"></i>Status
                                    </label>
                                    <select class="form-select form-control" id="status" name="status">
                                        <option value="">Submitted Only (Default)</option>
                                        <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-7 col-sm-6">
                                    <label for="search" class="form-label">
                                        <i class="fas fa-search me-1"></i>Search
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-user"></i>
                                        </span>
                                        <input type="text" class="form-control" id="search" name="search" 
                                               value="<?php echo htmlspecialchars($search); ?>" 
                                               placeholder="Search by faculty name or email..."
                                               autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" id="clearSearchBtn" onclick="clearSearch()" title="Clear search" style="<?php echo $search ? '' : 'display:none;'; ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="col-md-2 col-sm-12 d-flex align-items-end">
                                    <div class="d-flex gap-2 w-100">
                                        <button type="submit" class="btn btn-primary flex-fill">
                                            <i class="fas fa-search me-1"></i>Apply
                                        </button>
                                        <a href="pds_review.php" class="btn btn-outline-secondary" id="clearFiltersLink" title="Clear filters" style="<?php echo ($statusFilter || $search) ? '' : 'display:none;'; ?>">
                                            <i class="fas fa-redo"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div id="pdsTableContainer">
                            <?php echo getPdsTableContent($pdsSubmissions, $statusFilter, $search, $sortCol, $orderDir); ?>
                        </div>
                        <div id="pdsPaginationContainer">
                            <?php echo getPaginationHtml($page, $totalPages, $totalRows, $perPage, $statusFilter, $search, $sortCol, $orderDir); ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Enhanced PDS Review Modal -->
    <div class="modal fade" id="pdsModal" tabindex="-1" aria-labelledby="pdsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content enhanced-modal">
                <div class="modal-header enhanced-modal-header">
                    <div class="modal-header-content">
                        <h5 class="modal-title" id="pdsModalLabel">
                            <i class="fas fa-file-contract me-2"></i>PDS Review
                        </h5>
                        <div class="modal-subtitle" id="modalSubtitle">Loading...</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body enhanced-modal-body" id="pdsDetails">
                    <div class="loading-state text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading PDS details...</p>
                    </div>
                </div>
                <div class="modal-footer enhanced-modal-footer">
                    <div class="w-100">
                        <!-- Admin Notes Section (shown when PDS is submitted) -->
                        <div id="adminNotesSection" style="display: none;" class="mb-3">
                            <label for="adminNotes" class="form-label">
                                <i class="fas fa-comment me-1"></i>Admin Notes
                                <span class="text-danger" id="notesRequired" style="display: none;">*</span>
                            </label>
                            <textarea class="form-control" id="adminNotes" rows="3" 
                                      placeholder="Enter notes or feedback for the faculty member..."></textarea>
                            <small class="form-text text-muted">Notes are required when rejecting a PDS.</small>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>Close
                                </button>
                                <button type="button" class="btn btn-primary" id="printPDSBtn" onclick="printPDS()" style="display: none;">
                                    <i class="fas fa-print me-1"></i>Print
                                </button>
                            </div>
                            <input type="hidden" id="pdsReviewCsrfToken" value="<?php echo htmlspecialchars(generateFormToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <div id="actionButtons" style="display: none;">
                                <button type="button" class="btn btn-success" id="approveBtn" onclick="approvePDS()">
                                    <i class="fas fa-check-circle me-1"></i>Approve
                                </button>
                                <button type="button" class="btn btn-danger" id="rejectBtn" onclick="rejectPDS()">
                                    <i class="fas fa-times-circle me-1"></i>Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <?php admin_page_scripts(); ?>
    <style>
        /* Enhanced PDS Review Styles - Compact Stats Cards */
        .stats-card.enhanced {
            position: relative;
            /* padding: 1rem; */
            border-radius: 8px;
            display: flex;
            align-items: center;
            /* gap: 0.75rem; */
            transition: all 0.3s ease;
            overflow: hidden;
            min-height: auto;
        }
        
        .stats-card.enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
        }
        
        
        .stats-card.enhanced .stats-icon {
            width: 45px;
            height: 45px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: rgba(255,255,255,0.2);
            flex-shrink: 0;
        }
        
        .stats-card.enhanced .stats-content {
            flex: 1;
            min-width: 0;
        }
        
        .stats-card.enhanced .stats-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.15rem;
            line-height: 1.2;
        }
        
        .stats-card.enhanced .stats-label {
            font-size: 0.75rem;
            opacity: 0.9;
            font-weight: 500;
            line-height: 1.2;
        }
        
        .stats-card.enhanced .stats-trend {
            font-size: 1rem;
            opacity: 0.7;
            flex-shrink: 0;
        }
        
        .enhanced-card {
            border: none;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .enhanced-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 2px solid #e9ecef;
            padding: 1.25rem 1.5rem;
        }
        
        .enhanced-header h5 {
            display: flex;
            align-items: center;
            font-weight: 600;
            color: #212529;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        
        .filter-section .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .table-enhanced {
            margin-bottom: 0;
        }
        
        .table-enhanced thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            color: #6c757d;
            padding: 1rem;
        }
        
        .table-enhanced thead th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }
        
        
        .table-enhanced tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f3f5;
        }
        
        .table-row-enhanced {
            transition: all 0.2s ease;
        }
        
        .table-row-enhanced:hover {
            background: #f8f9fa;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #003366 0%, #005599 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        
        .email-link {
            color: #0066cc;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        
        .status-badge {
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
        }
        
        .status-badge.status-submitted {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }
        
        .status-badge.status-approved {
            background: #d4edda;
            color: #155724;
            border: 1px solid #28a745;
        }
        
        .status-badge.status-rejected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #dc3545;
        }
        
        .status-badge.status-draft {
            background: #e2e3e5;
            color: #383d41;
            border: 1px solid #6c757d;
        }
        
        
        .date-info {
            font-size: 0.875rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        
        .btn-action {
            min-width: 40px;
            padding: 0.5rem;
        }
        
        .empty-state-enhanced {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1.5rem;
        }
        
        .empty-state-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .empty-state-text {
            color: #6c757d;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .enhanced-modal {
            border: none;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .enhanced-modal-header {
            background: linear-gradient(135deg, #003366 0%, #005599 100%);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }
        
        .enhanced-modal-header .modal-title {
            color: white;
            font-weight: 600;
        }
        
        .enhanced-modal-header .btn-close {
            filter: invert(1);
            opacity: 0.8;
        }
        
        .enhanced-modal-header .btn-close:hover {
            opacity: 1;
        }
        
        .modal-header-content {
            flex: 1;
        }
        
        .modal-subtitle {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-top: 0.25rem;
        }
        
        .enhanced-modal-body {
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .enhanced-modal-footer {
            border-top: 1px solid #dee2e6;
            padding: 1.25rem 1.5rem;
            background: #f8f9fa;
        }
        
        .pds-section-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #003366;
        }
        
        .pds-section-card h6 {
            color: #003366;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .pds-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .pds-info-item {
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .pds-info-item strong {
            color: #495057;
            display: block;
            margin-bottom: 0.25rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .pds-info-item span {
            color: #212529;
            font-size: 0.875rem;
        }
        
        .pds-info-item a {
            color: #0066cc;
            text-decoration: none;
        }
        
        
        .table-responsive-enhanced {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .loading-state {
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        /* Accordion enhancements */
        .accordion-item {
            border: 1px solid #dee2e6;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .accordion-button {
            background-color: #f8f9fa;
            color: #212529;
            font-weight: 600;
            padding: 1rem 1.25rem;
        }
        
        .accordion-button:not(.collapsed) {
            background-color: #e7f3ff;
            color: #003366;
            box-shadow: none;
        }
        
        .accordion-button:focus {
            box-shadow: 0 0 0 0.25rem rgba(0, 51, 102, 0.15);
            border-color: #003366;
        }
        
        .accordion-body {
            padding: 1.5rem;
        }
        
        /* Status modal enhancements */
        .enhanced-modal-body .alert {
            margin-bottom: 1rem;
        }
        
        .enhanced-modal-body .form-text {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        
        /* Header actions */
        .header-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Action buttons in modal footer */
        #actionButtons {
            display: flex;
            gap: 0.5rem;
        }
        
        #actionButtons .btn {
            min-width: 120px;
        }
        
        #adminNotesSection {
            border-top: 1px solid #dee2e6;
            padding-top: 1rem;
            margin-top: 1rem;
        }
        
        #adminNotesSection .form-label {
            font-weight: 600;
            color: #495057;
        }
        
        #notesRequired {
            color: #dc3545;
        }
        
        @media (max-width: 768px) {
            .stats-card.enhanced {
                padding: 0.75rem;
                gap: 0.5rem;
            }
            
            .stats-card.enhanced .stats-icon {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
            
            .stats-card.enhanced .stats-number {
                font-size: 1.25rem;
            }
            
            .stats-card.enhanced .stats-label {
                font-size: 0.7rem;
            }
            
            .stats-card.enhanced .stats-trend {
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
            }
            
            .enhanced-modal-body {
                padding: 1rem;
            }
            
            .pds-info-grid {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .header-actions .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .stats-card.enhanced {
                padding: 0.65rem;
                flex-direction: row;
                text-align: left;
            }
            
            .stats-card.enhanced .stats-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }
            
            .stats-card.enhanced .stats-number {
                font-size: 1.15rem;
            }
            
            .stats-card.enhanced .stats-label {
                font-size: 0.65rem;
            }
            
            .stats-card.enhanced .stats-trend {
                display: none;
            }
        }
    </style>
    <script>
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function reviewPDS(pdsId) {
            const modal = new bootstrap.Modal(document.getElementById('pdsModal'));
            const modalBody = document.getElementById('pdsDetails');
            const printBtn = document.getElementById('printPDSBtn');
            
            // Show loading state
            modalBody.innerHTML = `
                <div class="loading-state text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading PDS details...</p>
                </div>
            `;
            
            modal.show();
            
            fetch(`get_pds.php?id=${pdsId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const pds = data.pds;
                        
                        // Update modal subtitle
                        document.getElementById('modalSubtitle').textContent = 
                            `${pds.first_name} ${pds.last_name} - ${pds.email}`;
                        
                        // Show print button
                        printBtn.style.display = 'inline-block';
                        printBtn.setAttribute('data-pds-id', pdsId);
                        
                        // Store PDS ID and status for action buttons
                        const actionButtons = document.getElementById('actionButtons');
                        const adminNotesSection = document.getElementById('adminNotesSection');
                        const adminNotes = document.getElementById('adminNotes');
                        const notesRequired = document.getElementById('notesRequired');
                        
                        // Show action buttons only for submitted PDS
                        if (pds.status === 'submitted') {
                            actionButtons.style.display = 'flex';
                            actionButtons.setAttribute('data-pds-id', pdsId);
                            adminNotesSection.style.display = 'block';
                            notesRequired.style.display = 'none';
                        } else {
                            actionButtons.style.display = 'none';
                            adminNotesSection.style.display = 'none';
                        }
                        
                        // Show existing admin notes if any
                        if (pds.admin_notes) {
                            adminNotes.value = pds.admin_notes;
                        } else {
                            adminNotes.value = '';
                        }
                        // Build sections for child arrays
                        const renderChildren = (children) => {
                            if (!children || children.length === 0) return '<p class="text-muted">No children listed.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Name</th><th>Date of Birth</th></tr></thead>
                                        <tbody>
                                            ${children.map(c => `<tr><td>${c.name || ''}</td><td>${c.dob || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        const renderEducation = (education) => {
                            if (!education || education.length === 0) return '<p class="text-muted">No education entries.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Level</th><th>School</th><th>Degree/Course</th><th>Year Graduated</th></tr></thead>
                                        <tbody>
                                            ${education.map(e => `<tr><td>${e.level || ''}</td><td>${e.school || ''}</td><td>${e.degree || ''}</td><td>${e.year_graduated || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        const renderEligibility = (eligibility) => {
                            if (!eligibility || eligibility.length === 0) return '<p class="text-muted">No civil service eligibility listed.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Title / Eligibility</th><th>Rating</th><th>Date of Exam</th><th>Place of Exam</th><th>License No.</th><th>Date of Validity</th></tr></thead>
                                        <tbody>
                                            ${eligibility.map(e => `<tr><td>${e.title || ''}</td><td>${e.rating || ''}</td><td>${e.date_of_exam || ''}</td><td>${e.place_of_exam || ''}</td><td>${e.license_number || ''}</td><td>${e.date_of_validity || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        const renderExperience = (exp) => {
                            if (!exp || exp.length === 0) return '<p class="text-muted">No work experience listed.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Inclusive Dates</th><th>Position</th><th>Company</th><th>Salary</th></tr></thead>
                                        <tbody>
                                            ${exp.map(r => `<tr><td>${r.dates || ''}</td><td>${r.position || ''}</td><td>${r.company || ''}</td><td>${r.salary || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        const renderVoluntary = (vol) => {
                            if (!vol || vol.length === 0) return '<p class="text-muted">No voluntary work listed.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Name & Address of Organization</th><th>Inclusive Dates</th><th>Number of Hours</th><th>Position / Nature of Work</th></tr></thead>
                                        <tbody>
                                            ${vol.map(r => `<tr><td>${r.org || ''}</td><td>${r.dates || ''}</td><td>${r.hours || ''}</td><td>${r.position || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        const renderLearning = (ld) => {
                            if (!ld || ld.length === 0) return '<p class="text-muted">No L&D entries listed.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Title of L&D/Training</th><th>Inclusive Dates</th><th>No. of Hours</th><th>Type of LD</th><th>Conducted/Sponsored By</th></tr></thead>
                                        <tbody>
                                            ${ld.map(r => `<tr><td>${r.title || ''}</td><td>${r.dates || ''}</td><td>${r.hours || ''}</td><td>${r.type || ''}</td><td>${r.conducted_by || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        const renderReferences = (refs) => {
                            if (!refs || refs.length === 0) return '<p class="text-muted">No references listed.</p>';
                            return `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead><tr><th>Name</th><th>Address</th><th>Phone</th></tr></thead>
                                        <tbody>
                                            ${refs.map(r => `<tr><td>${r.name || ''}</td><td>${r.address || ''}</td><td>${r.phone || ''}</td></tr>`).join('')}
                                        </tbody>
                                    </table>
                                </div>`;
                        };

                        let additionalQuestionHtml = '';
                        if (pds.additional_questions && Object.keys(pds.additional_questions).length > 0) {
                            const aq = pds.additional_questions;
                            const rows = [];
                            const label = (k) => {
                                switch(k) {
                                    case 'related_authority': return 'Are you related by consanguinity or affinity to the appointing/recommending authority?';
                                    case 'found_guilty_admin': return 'Have you ever been found guilty of any administrative offense?';
                                    case 'criminally_charged': return 'Have you been criminally charged before any court?';
                                    case 'convicted_crime': return 'Have you ever been convicted of any crime?';
                                    case 'separated_service': return 'Have you ever been separated from the service?';
                                    case 'candidate_election': return 'Have you ever been a candidate in any election?';
                                    default: return k;
                                }
                            };
                            for (const k in aq) {
                                if (!Object.prototype.hasOwnProperty.call(aq, k)) continue;
                                const v = aq[k] || '';
                                rows.push(`<tr><td style="width:65%;"><strong>${label(k)}</strong></td><td>${String(v).replace(/\n/g,'<br>')}</td></tr>`);
                            }
                            additionalQuestionHtml = `
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6>Additional Questions</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered">
                                                <tbody>
                                                    ${rows.join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }
                        // Enhanced PDS display with accordion structure
                        document.getElementById('pdsDetails').innerHTML = `
                            <!-- Summary Cards -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="pds-info-item">
                                        <strong><i class="fas fa-user me-1"></i>Faculty Name</strong>
                                        <span>${pds.first_name} ${pds.middle_name || ''} ${pds.last_name} ${pds.name_extension || ''}</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="pds-info-item">
                                        <strong><i class="fas fa-envelope me-1"></i>Email</strong>
                                        <span><a href="mailto:${pds.email}">${pds.email}</a></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="pds-info-item">
                                        <strong><i class="fas fa-info-circle me-1"></i>Status</strong>
                                        <span>
                                            <span class="badge status-badge status-${pds.status}">
                                                ${pds.status.charAt(0).toUpperCase() + pds.status.slice(1)}
                                            </span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="pds-info-item">
                                        <strong><i class="fas fa-calendar me-1"></i>Submitted</strong>
                                        <span>${pds.submitted_at || 'Not provided'}</span>
                                    </div>
                                </div>
                                ${pds.reviewed_at ? `
                                <div class="col-md-6">
                                    <div class="pds-info-item">
                                        <strong><i class="fas fa-check-circle me-1"></i>Reviewed</strong>
                                        <span>${pds.reviewed_at || 'Not provided'}</span>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                            ${pds.admin_notes ? `
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <strong><i class="fas fa-comment me-1"></i>Admin Notes:</strong>
                                        <p class="mb-0 mt-2" style="white-space: pre-wrap;">${escapeHtml(pds.admin_notes)}</p>
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                            
                            
                            <!-- Accordion for PDS Sections -->
                            <div class="accordion" id="pdsAccordion">
                                <!-- Personal Information -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingPersonal">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePersonal">
                                            <i class="fas fa-user me-2"></i>Personal Information
                                        </button>
                                    </h2>
                                    <div id="collapsePersonal" class="accordion-collapse collapse show" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            <div class="pds-info-grid">
                                                <div class="pds-info-item"><strong>Last Name</strong><span>${pds.last_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>First Name</strong><span>${pds.first_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Middle Name</strong><span>${pds.middle_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Name Extension</strong><span>${pds.name_extension || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Date of Birth</strong><span>${pds.date_of_birth || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Place of Birth</strong><span>${pds.place_of_birth || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Sex</strong><span>${pds.sex || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Civil Status</strong><span>${pds.civil_status || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Height (meters)</strong><span>${pds.height || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Weight (kg)</strong><span>${pds.weight || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Blood Type</strong><span>${pds.blood_type || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Citizenship</strong><span>${pds.citizenship || 'Not provided'}</span></div>
                                                ${pds.date_accomplished ? `<div class="pds-info-item"><strong>Date Accomplished</strong><span>${pds.date_accomplished}</span></div>` : ''}
                                                ${pds.sworn_date ? `<div class="pds-info-item"><strong>Sworn Date</strong><span>${pds.sworn_date}</span></div>` : ''}
                                                ${pds.position ? `<div class="pds-info-item"><strong>Position</strong><span>${pds.position}</span></div>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Government IDs -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingGovIds">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGovIds">
                                            <i class="fas fa-id-card me-2"></i>Government IDs
                                        </button>
                                    </h2>
                                    <div id="collapseGovIds" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            <div class="pds-info-grid">
                                                <div class="pds-info-item"><strong>GSIS ID No.</strong><span>${pds.gsis_id || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Pag-IBIG ID No.</strong><span>${pds.pagibig_id || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>PhilHealth No.</strong><span>${pds.philhealth_id || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>SSS No.</strong><span>${pds.sss_id || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>TIN</strong><span>${pds.tin || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Agency Employee No.</strong><span>${pds.agency_employee_no || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>AGENCY EMPLOYEE ID</strong><span>${pds.agency_employee_id || 'Not provided'}</span></div>
                                                ${pds.umid_id ? `<div class="pds-info-item"><strong>UMID ID No.</strong><span>${pds.umid_id}</span></div>` : ''}
                                                ${pds.philsys_number ? `<div class="pds-info-item"><strong>PhilSys Number (PSN)</strong><span>${pds.philsys_number}</span></div>` : ''}
                                                ${pds.dual_citizenship_country ? `<div class="pds-info-item"><strong>Dual Citizenship Country</strong><span>${pds.dual_citizenship_country}</span></div>` : ''}
                                                ${pds.government_id_number ? `<div class="pds-info-item"><strong>Government ID Number</strong><span>${pds.government_id_number}</span></div>` : ''}
                                                ${pds.government_id_issue_date ? `<div class="pds-info-item"><strong>Government ID Issue Date</strong><span>${pds.government_id_issue_date}</span></div>` : ''}
                                                ${pds.government_id_issue_place ? `<div class="pds-info-item"><strong>Government ID Issue Place</strong><span>${pds.government_id_issue_place}</span></div>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contact Information -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingContact">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseContact">
                                            <i class="fas fa-address-book me-2"></i>Contact Information
                                        </button>
                                    </h2>
                                    <div id="collapseContact" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            <h6 class="mb-3"><i class="fas fa-home me-2"></i>Residential Address</h6>
                                            <div class="pds-info-grid mb-4">
                                                ${pds.residential_house_no ? `<div class="pds-info-item"><strong>House/Block/Lot No.</strong><span>${pds.residential_house_no}</span></div>` : ''}
                                                ${pds.residential_street ? `<div class="pds-info-item"><strong>Street</strong><span>${pds.residential_street}</span></div>` : ''}
                                                ${pds.residential_subdivision ? `<div class="pds-info-item"><strong>Subdivision/Village</strong><span>${pds.residential_subdivision}</span></div>` : ''}
                                                ${pds.residential_barangay ? `<div class="pds-info-item"><strong>Barangay</strong><span>${pds.residential_barangay}</span></div>` : ''}
                                                ${pds.residential_city ? `<div class="pds-info-item"><strong>City/Municipality</strong><span>${pds.residential_city}</span></div>` : ''}
                                                ${pds.residential_province ? `<div class="pds-info-item"><strong>Province</strong><span>${pds.residential_province}</span></div>` : ''}
                                                <div class="pds-info-item"><strong>Full Residential Address</strong><span>${pds.residential_address || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Residential Zip Code</strong><span>${pds.residential_zipcode || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Residential Tel. No.</strong><span>${pds.residential_telno || 'Not provided'}</span></div>
                                            </div>
                                            
                                            <h6 class="mb-3"><i class="fas fa-map-marker-alt me-2"></i>Permanent Address</h6>
                                            <div class="pds-info-grid mb-4">
                                                ${pds.permanent_house_no ? `<div class="pds-info-item"><strong>House/Block/Lot No.</strong><span>${pds.permanent_house_no}</span></div>` : ''}
                                                ${pds.permanent_street ? `<div class="pds-info-item"><strong>Street</strong><span>${pds.permanent_street}</span></div>` : ''}
                                                ${pds.permanent_subdivision ? `<div class="pds-info-item"><strong>Subdivision/Village</strong><span>${pds.permanent_subdivision}</span></div>` : ''}
                                                ${pds.permanent_barangay ? `<div class="pds-info-item"><strong>Barangay</strong><span>${pds.permanent_barangay}</span></div>` : ''}
                                                ${pds.permanent_city ? `<div class="pds-info-item"><strong>City/Municipality</strong><span>${pds.permanent_city}</span></div>` : ''}
                                                ${pds.permanent_province ? `<div class="pds-info-item"><strong>Province</strong><span>${pds.permanent_province}</span></div>` : ''}
                                                <div class="pds-info-item"><strong>Full Permanent Address</strong><span>${pds.permanent_address || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Permanent Zip Code</strong><span>${pds.permanent_zipcode || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Permanent Tel. No.</strong><span>${pds.permanent_telno || 'Not provided'}</span></div>
                                            </div>
                                            
                                            <h6 class="mb-3"><i class="fas fa-phone me-2"></i>Contact Information</h6>
                                            <div class="pds-info-grid">
                                                <div class="pds-info-item"><strong>Email Address</strong><span>${pds.email || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Mobile No.</strong><span>${pds.mobile_no || 'Not provided'}</span></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Family Background -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingFamily">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFamily">
                                            <i class="fas fa-users me-2"></i>Family Background
                                        </button>
                                    </h2>
                                    <div id="collapseFamily" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            <h6 class="mb-3"><i class="fas fa-heart me-2"></i>Spouse Information</h6>
                                            <div class="pds-info-grid mb-4">
                                                <div class="pds-info-item"><strong>Spouse Last Name</strong><span>${pds.spouse_last_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Spouse First Name</strong><span>${pds.spouse_first_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Spouse Middle Name</strong><span>${pds.spouse_middle_name || 'Not provided'}</span></div>
                                                ${pds.spouse_name_extension ? `<div class="pds-info-item"><strong>Spouse Name Extension</strong><span>${pds.spouse_name_extension}</span></div>` : ''}
                                                <div class="pds-info-item"><strong>Spouse Occupation</strong><span>${pds.spouse_occupation || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Spouse Employer/Business Name</strong><span>${pds.spouse_employer || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Spouse Business Address</strong><span>${pds.spouse_business_address || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Spouse Business Telephone No.</strong><span>${pds.spouse_telno || 'Not provided'}</span></div>
                                            </div>
                                            
                                            <h6 class="mb-3"><i class="fas fa-male me-2"></i>Father's Information</h6>
                                            <div class="pds-info-grid mb-4">
                                                <div class="pds-info-item"><strong>Father Last Name</strong><span>${pds.father_last_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Father First Name</strong><span>${pds.father_first_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Father Middle Name</strong><span>${pds.father_middle_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Father Name Extension</strong><span>${pds.father_name_extension || 'Not provided'}</span></div>
                                            </div>
                                            
                                            <h6 class="mb-3"><i class="fas fa-female me-2"></i>Mother's Information</h6>
                                            <div class="pds-info-grid">
                                                <div class="pds-info-item"><strong>Mother Last Name</strong><span>${pds.mother_last_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Mother First Name</strong><span>${pds.mother_first_name || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Mother Middle Name</strong><span>${pds.mother_middle_name || 'Not provided'}</span></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Children -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingChildren">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseChildren">
                                            <i class="fas fa-child me-2"></i>Children
                                        </button>
                                    </h2>
                                    <div id="collapseChildren" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderChildren(pds.children)}
                                        </div>
                                    </div>
                                </div>

                                <!-- Educational Background -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingEducation">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEducation">
                                            <i class="fas fa-graduation-cap me-2"></i>Educational Background
                                        </button>
                                    </h2>
                                    <div id="collapseEducation" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderEducation(pds.education)}
                                        </div>
                                    </div>
                                </div>

                                <!-- Civil Service Eligibility -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingEligibility">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEligibility">
                                            <i class="fas fa-certificate me-2"></i>Civil Service Eligibility
                                        </button>
                                    </h2>
                                    <div id="collapseEligibility" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderEligibility(pds.eligibility)}
                                        </div>
                                    </div>
                                </div>

                                <!-- Work Experience -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingExperience">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseExperience">
                                            <i class="fas fa-briefcase me-2"></i>Work Experience
                                        </button>
                                    </h2>
                                    <div id="collapseExperience" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderExperience(pds.experience)}
                                        </div>
                                    </div>
                                </div>

                                <!-- Voluntary Work -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingVoluntary">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVoluntary">
                                            <i class="fas fa-hands-helping me-2"></i>Voluntary Work / Organization Involvement
                                        </button>
                                    </h2>
                                    <div id="collapseVoluntary" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderVoluntary(pds.voluntary)}
                                        </div>
                                    </div>
                                </div>

                                <!-- Learning & Development -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingLearning">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseLearning">
                                            <i class="fas fa-book-reader me-2"></i>Learning and Development (L&D)
                                        </button>
                                    </h2>
                                    <div id="collapseLearning" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderLearning(pds.learning)}
                                        </div>
                                    </div>
                                </div>

                                <!-- Other Information -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingOther">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOther">
                                            <i class="fas fa-info-circle me-2"></i>Other Information
                                        </button>
                                    </h2>
                                    <div id="collapseOther" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            <div class="pds-info-grid">
                                                <div class="pds-info-item"><strong>Special Skills and Hobbies</strong><span>${pds.other_info?.skills || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Non-Academic Distinctions / Recognition</strong><span>${pds.other_info?.distinctions || 'Not provided'}</span></div>
                                                <div class="pds-info-item"><strong>Membership in Association/Organization</strong><span>${pds.other_info?.memberships || 'Not provided'}</span></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Additional Questions -->
                                ${additionalQuestionHtml ? `
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingAdditional">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdditional">
                                            <i class="fas fa-question-circle me-2"></i>Additional Questions
                                        </button>
                                    </h2>
                                    <div id="collapseAdditional" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${additionalQuestionHtml.replace('<div class="row mt-3">', '<div class="row">').replace('<h6>Additional Questions</h6>', '')}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}

                                <!-- References -->
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="headingReferences">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseReferences">
                                            <i class="fas fa-address-card me-2"></i>References
                                        </button>
                                    </h2>
                                    <div id="collapseReferences" class="accordion-collapse collapse" data-bs-parent="#pdsAccordion">
                                        <div class="accordion-body">
                                            ${renderReferences(pds.references)}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Failed to load PDS details. Please try again.
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            An error occurred while loading PDS details: ${error.message}
                        </div>
                    `;
                });
        }
        
        function approvePDS() {
            const actionButtons = document.getElementById('actionButtons');
            const pdsId = actionButtons.getAttribute('data-pds-id');
            const adminNotes = document.getElementById('adminNotes').value.trim();
            
            if (!pdsId) {
                alert('Error: PDS ID not found');
                return;
            }
            
            if (!confirm('Are you sure you want to approve this PDS?')) {
                return;
            }
            
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'pds_review.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'approve';
            form.appendChild(actionInput);
            
            const pdsIdInput = document.createElement('input');
            pdsIdInput.type = 'hidden';
            pdsIdInput.name = 'pds_id';
            pdsIdInput.value = pdsId;
            form.appendChild(pdsIdInput);
            
            const notesInput = document.createElement('input');
            notesInput.type = 'hidden';
            notesInput.name = 'admin_notes';
            notesInput.value = adminNotes;
            form.appendChild(notesInput);
            
            const csrfEl = document.getElementById('pdsReviewCsrfToken');
            if (csrfEl) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfEl.value;
                form.appendChild(csrfInput);
            }
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function rejectPDS() {
            const actionButtons = document.getElementById('actionButtons');
            const pdsId = actionButtons.getAttribute('data-pds-id');
            const adminNotes = document.getElementById('adminNotes').value.trim();
            const notesRequired = document.getElementById('notesRequired');
            
            if (!pdsId) {
                alert('Error: PDS ID not found');
                return;
            }
            
            // Require admin notes for rejection
            if (!adminNotes) {
                notesRequired.style.display = 'inline';
                document.getElementById('adminNotes').focus();
                alert('Admin notes are required when rejecting a PDS. Please provide feedback to the faculty member.');
                return;
            }
            
            if (!confirm('Are you sure you want to reject this PDS? The faculty member will be notified via email.')) {
                return;
            }
            
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'pds_review.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'reject';
            form.appendChild(actionInput);
            
            const pdsIdInput = document.createElement('input');
            pdsIdInput.type = 'hidden';
            pdsIdInput.name = 'pds_id';
            pdsIdInput.value = pdsId;
            form.appendChild(pdsIdInput);
            
            const notesInput = document.createElement('input');
            notesInput.type = 'hidden';
            notesInput.name = 'admin_notes';
            notesInput.value = adminNotes;
            form.appendChild(notesInput);
            
            const csrfEl = document.getElementById('pdsReviewCsrfToken');
            if (csrfEl) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfEl.value;
                form.appendChild(csrfInput);
            }
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function exportPDS() {
            try {
                const table = document.querySelector('.table-enhanced');
                if (!table) {
                    showError('No data to export.');
                    return;
                }
                
                const rows = Array.from(table.querySelectorAll('tr'));
                const csvContent = rows.map(row => {
                    const cells = Array.from(row.querySelectorAll('th, td'));
                    // Remove action buttons and icons from export
                    return cells.slice(0, -1).map(cell => {
                        let text = cell.textContent.trim();
                        // Remove icon text and extra spaces
                        text = text.replace(/\s+/g, ' ').trim();
                        return `"${text}"`;
                    }).join(',');
                }).join('\n');
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `pds_export_${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                showSuccess('PDS data exported successfully!');
            } catch (error) {
                console.error('Export error:', error);
                showError('Failed to export PDS data.');
            }
        }
        
        function refreshPage() {
            window.location.reload();
        }
        
        function clearSearch() {
            document.getElementById('search').value = '';
            if (window.applyFiltersAjax) {
                window.applyFiltersAjax();
            } else {
                document.getElementById('filterForm').submit();
            }
        }
        
        // Real-time filtering via AJAX (no page reload)
        (function initRealtimeFilters() {
            const form = document.getElementById('filterForm');
            const searchInput = document.getElementById('search');
            const statusSelect = document.getElementById('status');
            const container = document.getElementById('pdsTableContainer');
            const countBadge = document.getElementById('pdsCountBadge');
            const paginationContainer = document.getElementById('pdsPaginationContainer');
            const clearSearchBtn = document.getElementById('clearSearchBtn');
            const clearFiltersLink = document.getElementById('clearFiltersLink');
            if (!form || !searchInput || !container) return;
            
            let searchDebounceTimer;
            let requestInFlight = false;
            const debounceMs = 350;
            
            function applyFiltersAjax() {
                const status = statusSelect ? statusSelect.value : '';
                const search = searchInput.value.trim();
                const sortInput = document.getElementById('filterSort');
                const orderInput = document.getElementById('filterOrder');
                const sort = sortInput ? sortInput.value : 'submitted';
                const order = orderInput ? orderInput.value : 'desc';
                const params = new URLSearchParams();
                params.set('ajax', '1');
                if (status) params.set('status', status);
                if (search) params.set('search', search);
                params.set('sort', sort || 'submitted');
                params.set('order', (order || 'desc').toLowerCase());
                const url = 'pds_review.php?' + params.toString();
                
                if (requestInFlight) return;
                requestInFlight = true;
                if (container.querySelector('.table-responsive-enhanced')) {
                    container.querySelector('.table-responsive-enhanced').style.opacity = '0.6';
                } else if (container.querySelector('.empty-state-enhanced')) {
                    container.querySelector('.empty-state-enhanced').style.opacity = '0.6';
                }
                
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        container.innerHTML = data.html;
                        if (countBadge) countBadge.textContent = data.count;
                        if (paginationContainer && data.pagination_html !== undefined) paginationContainer.innerHTML = data.pagination_html;
                        if (clearSearchBtn) clearSearchBtn.style.display = search ? '' : 'none';
                        if (clearFiltersLink) clearFiltersLink.style.display = (status || search) ? '' : 'none';
                        var urlParams = new URLSearchParams();
                        if (status) urlParams.set('status', status);
                        if (search) urlParams.set('search', search);
                        if (sort) urlParams.set('sort', sort);
                        if (order) urlParams.set('order', order);
                        var newUrl = 'pds_review.php' + (urlParams.toString() ? '?' + urlParams.toString() : '');
                        if (history.replaceState) history.replaceState(null, '', newUrl);
                    })
                    .catch(function() {
                        form.submit();
                    })
                    .finally(function() {
                        requestInFlight = false;
                        if (container.querySelector('.table-responsive-enhanced')) {
                            container.querySelector('.table-responsive-enhanced').style.opacity = '';
                        } else if (container.querySelector('.empty-state-enhanced')) {
                            container.querySelector('.empty-state-enhanced').style.opacity = '';
                        }
                    });
            }
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                applyFiltersAjax();
            });
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(applyFiltersAjax, debounceMs);
            });
            
            if (statusSelect) {
                statusSelect.addEventListener('change', applyFiltersAjax);
            }
            
            window.applyFiltersAjax = applyFiltersAjax;
        })();
        
        function printPDS() {
            const printBtn = document.getElementById('printPDSBtn');
            const pdsId = printBtn.getAttribute('data-pds-id');
            if (pdsId) {
                window.open(`pds_print.php?id=${pdsId}`, '_blank');
            } else {
                // Fallback: print current modal content
                const modalContent = document.getElementById('pdsDetails').innerHTML;
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>PDS Print</title>
                            <style>
                                body { font-family: Arial, sans-serif; padding: 20px; }
                                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                                th { background-color: #f2f2f2; }
                            </style>
                        </head>
                        <body>
                            ${modalContent}
                        </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.print();
            }
        }
        
        function showError(message) {
            // You can implement a toast notification here
            alert('Error: ' + message);
        }
        
        function showSuccess(message) {
            // You can implement a toast notification here
            console.log('Success: ' + message);
        }
        
        // Table header sorting: update form sort/order and apply filters
        document.addEventListener('click', function(e) {
            const th = e.target && e.target.closest('.table-enhanced thead th.sortable');
            if (!th) return;
            e.preventDefault();
            const col = th.getAttribute('data-sort');
            if (!col) return;
            const sortInput = document.getElementById('filterSort');
            const orderInput = document.getElementById('filterOrder');
            if (!sortInput || !orderInput) return;
            const currentSort = sortInput.value || 'submitted';
            const currentOrder = (orderInput.value || 'desc').toLowerCase();
            if (currentSort === col) {
                orderInput.value = currentOrder === 'asc' ? 'DESC' : 'ASC';
            } else {
                sortInput.value = col;
                orderInput.value = col === 'name' || col === 'email' ? 'ASC' : 'DESC';
            }
            if (window.applyFiltersAjax) {
                window.applyFiltersAjax();
            } else {
                document.getElementById('filterForm').submit();
            }
        });
        
        // Auto-open review modal when landing with ?id=X (e.g. from dashboard)
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            const pdsId = urlParams.get('id');
            if (pdsId && /^\d+$/.test(pdsId)) {
                reviewPDS(parseInt(pdsId, 10));
            }
        })();
    </script>
</body>
</html>







