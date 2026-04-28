<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$stats = getStats();

// Get recent faculty registrations
$stmt = $db->prepare("SELECT u.*, fp.department, fp.position FROM users u LEFT JOIN faculty_profiles fp ON u.id = fp.user_id WHERE u.user_type = 'faculty' ORDER BY u.created_at DESC LIMIT 10");
$stmt->execute();
$recentFaculty = $stmt->fetchAll();

// Get pending PDS submissions
$stmt = $db->prepare("SELECT pds.*, u.first_name, u.last_name, u.email FROM faculty_pds pds JOIN users u ON pds.faculty_id = u.id WHERE pds.status = 'submitted' ORDER BY pds.submitted_at DESC LIMIT 5");
$stmt->execute();
$pendingPDS = $stmt->fetchAll();

// Get pending file submissions
$stmt = $db->prepare("SELECT fs.*, u.first_name, u.last_name, r.title as requirement_title FROM faculty_submissions fs JOIN users u ON fs.faculty_id = u.id JOIN requirements r ON fs.requirement_id = r.id WHERE fs.status = 'pending' ORDER BY fs.submitted_at DESC LIMIT 5");
$stmt->execute();
$pendingSubmissions = $stmt->fetchAll();

// Get active requirements (optimized: only select needed columns)
$stmt = $db->prepare("SELECT id, title, description, deadline, created_at FROM requirements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$activeRequirements = $stmt->fetchAll();

// Get system logs for recent activity
$stmt = $db->prepare("SELECT sl.*, u.first_name, u.last_name FROM system_logs sl LEFT JOIN users u ON sl.user_id = u.id ORDER BY sl.created_at DESC LIMIT 10");
$stmt->execute();
$recentActivity = $stmt->fetchAll();

// Admin interface to reject a submission
if(isset($_POST['reject'])) {
    // Validate CSRF token to prevent cross-site request forgery
    if (!validateFormToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "Invalid form submission. Please try again.";
        header('Location: dashboard.php');
        exit();
    }
    $submissionId = (int)$_POST['submission_id']; // Cast to int for safety
    $adminNotes = sanitizeInput($_POST['admin_notes'] ?? '');
    rejectSubmission($submissionId, $adminNotes);
    $_SESSION['success'] = "Submission rejected successfully.";
    header('Location: dashboard.php');
    exit();
}
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
    <meta name="description" content="Admin Dashboard - WPU Faculty and Staff Management System">
    <meta http-equiv="x-dns-prefetch-control" content="on">
    <title>Admin Dashboard - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    // Cache version for cache busting
    $cacheVersion = '1.0.0';
    ?>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo asset_url('css/style.css', true); ?>?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>?v=<?php echo $cacheVersion; ?>" as="script">
    <link rel="preload" href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" as="style">
    <!-- Prefetch font (will be loaded by CSS when needed) -->
    <link rel="prefetch" href="<?php echo asset_url('vendor/fontawesome/webfonts/fa-solid-900.woff2', true); ?>" as="font" type="font/woff2" crossorigin>
    
    <!-- Critical CSS inline for fastest first paint -->
    <style>
    :root{--primary:#003366;--bg:#f8fafc;--white:#fff;--text:#0f172a;--border:#e2e8f0}*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);font-size:14px}.header{position:fixed;top:0;left:0;right:0;height:56px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;z-index:1030}.sidebar{position:fixed;top:0;left:0;width:280px;height:100vh;background:var(--white);border-right:1px solid var(--border);z-index:1028;transform:translateX(-100%);transition:transform .25s}.sidebar.show{transform:translateX(0)}@media(min-width:992px){.header{left:280px;width:calc(100% - 280px)}.sidebar{transform:translateX(0)}}.main-content{margin-top:56px;padding:1rem;min-height:calc(100vh - 56px)}@media(min-width:992px){.main-content{margin-left:280px;width:calc(100% - 280px)}}.card{background:var(--white);border:1px solid var(--border);border-radius:.5rem}
    </style>
    
    <!-- Core stylesheets -->
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/admin-portal.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    
    <!-- Non-critical CSS (async load) -->
    <link href="<?php echo asset_url('css/mobile.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="<?php echo asset_url('css/mobile.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-1">
                                <li class="breadcrumb-item active" aria-current="page">
                                </li>
                            </ol>
                        </nav>
                        <div class="page-title">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Admin Dashboard</span>
                        </div>
                    </div>
                    <div class="d-none d-md-flex align-items-center gap-2">
            
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                    </div>
                </div>

                <?php 
                    displayMessage(); 
                    $firstName = explode(' ', trim($_SESSION['user_name']))[0] ?? 'Administrator';
                    $totalPending = $stats['pds_submitted'] + $stats['pending_submissions'];
                ?>

                <section class="admin-hero mb-3">
                    <div class="admin-hero-content">
                        <span class="admin-hero-title">Welcome back, <?php echo htmlspecialchars($firstName); ?>!</span>
                        <p class="admin-hero-support mb-2">
                            You have <strong><?php echo $stats['total_faculty']; ?></strong> total employee<?php echo $stats['total_faculty'] === 1 ? '' : 's'; ?> (staff and faculty)
                            and <strong><?php echo $totalPending; ?></strong> pending item<?php echo $totalPending === 1 ? '' : 's'; ?> requiring your attention.
                        </p>
                        <div class="admin-chip-list">
                            <span class="admin-chip"><i class="fas fa-users me-1"></i><?php echo $stats['total_faculty']; ?> Total Employees</span>
                            <span class="admin-chip"><i class="fas fa-tasks me-1"></i><?php echo $stats['active_requirements']; ?> Active Requirements</span>
                            <?php if ($totalPending > 0): ?>
                                <span class="admin-chip" style="background: rgba(217, 119, 6, 0.15); color: #d97706;">
                                    <i class="fas fa-exclamation-circle me-1"></i><?php echo $totalPending; ?> Pending
                                </span>
                            <?php endif; ?>
                            <?php if (defined('OFFLINE_MODE') && OFFLINE_MODE === true): ?>
                                <span class="admin-chip" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;" title="System is running in offline mode. Email notifications are disabled.">
                                    <i class="fas fa-wifi-slash me-1"></i>Offline Mode
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="admin-hero-actions">
                        <a href="faculty.php" class="btn btn-light">
                            <i class="fas fa-users me-1"></i>Manage Faculty
                        </a>
                        <a href="pds_review.php" class="btn btn-light">
                            <i class="fas fa-file-contract me-1"></i>Review PDS
                        </a>
                        
                    </div>
                </section>

             

                <div class="admin-grid">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-user-plus me-2 text-primary"></i>Recent Faculty Registrations
                            </h5>
                            <a href="faculty.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i>View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentFaculty)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <span class="empty-title">No recent registrations</span>
                                    <p class="mb-0">New faculty registrations will appear here.</p>
                                    <a href="create_faculty.php" class="btn btn-primary mt-2">
                                        <i class="fas fa-plus-circle me-1"></i>Add Faculty Member
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th class="d-none d-lg-table-cell">Department</th>
                                                <th>Status</th>
                                                <th class="d-none d-xl-table-cell">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentFaculty as $faculty): ?>
                                                <tr>
                                                    <td data-label="Name">
                                                        <strong><?php echo htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']); ?></strong>
                                                        <div class="text-muted small d-lg-none">
                                                            <?php echo htmlspecialchars($faculty['email']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="d-none d-lg-table-cell">
                                                        <?php echo htmlspecialchars($faculty['department'] ?: 'Not specified'); ?>
                                                    </td>
                                                    <td data-label="Status">
                                                        <?php if ($faculty['is_verified']): ?>
                                                            <span class="badge bg-success">Verified</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="d-none d-xl-table-cell">
                                                        <small><?php echo formatDate($faculty['created_at'], 'M j, Y'); ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-file-contract me-2 text-primary"></i>Pending PDS Submissions
                            </h5>
                            <?php if (!empty($pendingPDS)): ?>
                                <span class="badge bg-warning">
                                    <?php echo count($pendingPDS); ?> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pendingPDS)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span class="empty-title">All caught up!</span>
                                    <p class="mb-0">No pending PDS submissions to review.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($pendingPDS as $pds): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($pds['first_name'] . ' ' . $pds['last_name']); ?></h6>
                                                    <p class="mb-1 text-muted small"><?php echo htmlspecialchars($pds['email']); ?></p>
                                                    <small class="text-muted">Submitted: <?php echo formatDate($pds['submitted_at'], 'M j, Y g:i A'); ?></small>
                                                </div>
                                                <div>
                                                    <a href="pds_review.php?id=<?php echo $pds['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye me-1"></i>Review
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="admin-grid">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-upload me-2 text-primary"></i>Pending File Submissions
                            </h5>
                            <?php if (!empty($pendingSubmissions)): ?>
                                <span class="badge bg-warning">
                                    <?php echo count($pendingSubmissions); ?> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pendingSubmissions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-double"></i>
                                    <span class="empty-title">All reviewed!</span>
                                    <p class="mb-0">No pending file submissions at this time.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($pendingSubmissions as $submission): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($submission['requirement_title']); ?></h6>
                                                    <p class="mb-1 small">Submitted by: <strong><?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></strong></p>
                                                    <small class="text-muted d-block mb-1">
                                                        <i class="fas fa-file-alt me-1"></i><?php echo htmlspecialchars($submission['original_filename']); ?>
                                                    </small>
                                                    <small class="text-muted">Submitted: <?php echo formatDate($submission['submitted_at'], 'M j, Y g:i A'); ?></small>
                                                </div>
                                                <div>
                                                    <a href="submissions.php?view=<?php echo $submission['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye me-1"></i>Review
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($activeRequirements)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-tasks me-2 text-primary"></i>Active Requirements
                            </h5>
                            <a href="requirements.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i>Manage
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <?php foreach (array_slice($activeRequirements, 0, 5) as $req): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($req['title']); ?></h6>
                                                <p class="mb-1 text-muted small"><?php echo htmlspecialchars(substr($req['description'], 0, 80)) . (strlen($req['description']) > 80 ? '…' : ''); ?></p>
                                                <?php if ($req['deadline']): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-calendar me-1"></i>Deadline: <?php echo formatDate($req['deadline'], 'M j, Y'); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exportModalLabel">
                        <i class="fas fa-download me-2"></i>Export Report
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="exportForm" method="GET" action="export.php">
                        <div class="mb-3">
                            <label for="exportType" class="form-label">Select Report Type</label>
                            <select class="form-select" id="exportType" name="type" required>
                                <option value="">Choose report...</option>
                                <option value="faculty_list">Faculty List</option>
                                <option value="submissions_report">Submissions Report</option>
                                <option value="pds_report">PDS Report</option>
                                <option value="activity_logs">Activity Logs</option>
                                <option value="requirements_summary">Requirements Summary</option>
                            </select>
                        </div>
                        
                        <!-- Filters (show/hide based on selection) -->
                        <div id="filterOptions" style="display: none;">
                            <hr>
                            <h6 class="mb-3">Optional Filters</h6>
                            
                            <!-- Date Range Filter -->
                            <div id="dateFilters" class="mb-3">
                                <label class="form-label">Date Range</label>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <input type="date" class="form-control" name="date_from" placeholder="From">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="date" class="form-control" name="date_to" placeholder="To">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Status Filter -->
                            <div id="statusFilter" class="mb-3" style="display: none;">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            
                            <!-- Department Filter -->
                            <div id="departmentFilter" class="mb-3" style="display: none;">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department">
                                    <option value="">All Departments</option>
                                    <option value="College of Engineering">College of Engineering</option>
                                    <option value="College of Education">College of Education</option>
                                    <option value="College of Business">College of Business</option>
                                    <option value="College of Arts and Sciences">College of Arts and Sciences</option>
                                    <option value="College of Agriculture">College of Agriculture</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="exportForm" class="btn btn-primary">
                        <i class="fas fa-download me-1"></i>Download CSV
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>?v=<?php echo $cacheVersion; ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo asset_url('js/performance.js', true); ?>?v=<?php echo $cacheVersion; ?>" defer></script>
    <script src="<?php echo asset_url('js/mobile.js', true); ?>?v=<?php echo $cacheVersion; ?>" defer></script>
    <script>
        // Initialize Bootstrap tooltips (desktop only)
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth >= 768) {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
        });

        // Export modal functionality
        document.getElementById('exportType').addEventListener('change', function() {
            const selectedType = this.value;
            const filterOptions = document.getElementById('filterOptions');
            const statusFilter = document.getElementById('statusFilter');
            const departmentFilter = document.getElementById('departmentFilter');
            const dateFilters = document.getElementById('dateFilters');
            
            // Hide all filters first
            filterOptions.style.display = 'none';
            statusFilter.style.display = 'none';
            departmentFilter.style.display = 'none';
            dateFilters.style.display = 'none';
            
            // Show relevant filters based on selection
            if (selectedType) {
                filterOptions.style.display = 'block';
                
                if (selectedType === 'faculty_list') {
                    departmentFilter.style.display = 'block';
                } else if (selectedType === 'submissions_report' || selectedType === 'pds_report') {
                    statusFilter.style.display = 'block';
                    dateFilters.style.display = 'block';
                } else if (selectedType === 'activity_logs') {
                    dateFilters.style.display = 'block';
                }
            }
        });
    </script>
</body>
</html>
