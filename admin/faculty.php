<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/logs/php_errors.log');

// Set error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $errorMsg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($errorMsg);
    if (ini_get('display_errors')) {
        echo "<div style='background: #ff0000; color: white; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3>PHP Error Detected</h3>";
        echo "<p><strong>Error:</strong> $errstr</p>";
        echo "<p><strong>File:</strong> $errfile</p>";
        echo "<p><strong>Line:</strong> $errline</p>";
        echo "</div>";
    }
    return false; // Let PHP handle the error normally
});

// Set exception handler
set_exception_handler(function($exception) {
    $errorMsg = "Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine();
    error_log($errorMsg);
    if (ini_get('display_errors')) {
        echo "<div style='background: #ff0000; color: white; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3>Uncaught Exception</h3>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "</p>";
        echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
});

// Disable output buffering to see errors immediately
if (ob_get_level()) {
    ob_end_clean();
}

try {
    require_once '../includes/config.php';
} catch (Exception $e) {
    die("Error loading config.php: " . htmlspecialchars($e->getMessage()));
} catch (Error $e) {
    die("Fatal error loading config.php: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    require_once '../includes/functions.php';
} catch (Exception $e) {
    die("Error loading functions.php: " . htmlspecialchars($e->getMessage()));
} catch (Error $e) {
    die("Fatal error loading functions.php: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    require_once '../includes/database.php';
} catch (Exception $e) {
    die("Error loading database.php: " . htmlspecialchars($e->getMessage()));
} catch (Error $e) {
    die("Fatal error loading database.php: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    requireAdmin();
} catch (Exception $e) {
    die("Error in requireAdmin(): " . htmlspecialchars($e->getMessage()));
} catch (Error $e) {
    die("Fatal error in requireAdmin(): " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Database connection error: " . htmlspecialchars($e->getMessage()) . "<br><br>Please check your database credentials in includes/config.php");
} catch (Error $e) {
    die("Fatal database error: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

// Fetch departments for filter dropdown
$deptStmt = $db->prepare("SELECT id, name FROM departments ORDER BY name");
try { 
    $deptStmt->execute(); 
    $departments = $deptStmt->fetchAll(); 
} catch (Exception $e) { 
    $departments = []; 
}
if (!is_array($departments)) { 
    $departments = []; 
}

$action = $_GET['action'] ?? 'list';
$facultyId = $_GET['id'] ?? null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';
    
    if ($action === 'update_status') {
        if (!isSuperAdmin()) {
            $_SESSION['error'] = 'Access denied. Only super admins can activate or deactivate employee accounts.';
            header('Location: faculty.php');
            exit();
        }
        $facultyId = (int)$_POST['faculty_id'];
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Allow status update for both faculty and staff
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ? AND user_type IN ('faculty','staff')");

        if ($stmt->execute([$isActive, $facultyId])) {
            $_SESSION['success'] = 'Account status updated successfully!';
            logAction('ACCOUNT_STATUS_UPDATE', "Updated user status for ID: $facultyId");
        } else {
            $_SESSION['error'] = 'Failed to update faculty status.';
        }
        
        header('Location: faculty.php');
        exit();
    }
}

// Get faculty list with filters
$whereClause = "u.user_type IN ('faculty','staff')";
$params = [];

$statusFilter = $_GET['status'] ?? '';
if ($statusFilter) {
    $whereClause .= " AND u.is_active = ?";
    $params[] = ($statusFilter === 'active') ? 1 : 0;
}

$departmentFilter = $_GET['department'] ?? '';
if ($departmentFilter) {
    $whereClause .= " AND fp.department = ?";
    $params[] = $departmentFilter;
}

// Verification filter removed - all faculty are now automatically verified when created by admin

$search = $_GET['search'] ?? '';
if ($search) {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Pagination settings
$itemsPerPage = 20;
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// First, get total count for pagination
$countSql = "SELECT COUNT(DISTINCT u.id) as total
        FROM users u
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
        WHERE $whereClause";

try {
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch()['total'];
    $totalPages = ceil($totalCount / $itemsPerPage);
    
    // Ensure current page doesn't exceed total pages
    if ($currentPage > $totalPages && $totalPages > 0) {
        $currentPage = $totalPages;
        $offset = ($currentPage - 1) * $itemsPerPage;
    }
} catch (PDOException $e) {
    error_log("Faculty count query error: " . $e->getMessage());
    $totalCount = 0;
    $totalPages = 0;
} catch (Exception $e) {
    error_log("Faculty count query exception: " . $e->getMessage());
    $totalCount = 0;
    $totalPages = 0;
}

// Simplified query without GROUP BY - each user should only have one profile
$sql = "SELECT u.*, fp.employee_id, fp.department, fp.position, fp.employment_status, fp.hire_date,
        (SELECT ps1.salary_grade FROM position_salary ps1 WHERE ps1.position_title = fp.position ORDER BY ps1.id LIMIT 1) as salary_grade,
        (SELECT ps1.annual_salary FROM position_salary ps1 WHERE ps1.position_title = fp.position ORDER BY ps1.id LIMIT 1) as annual_salary
        FROM users u
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
        WHERE $whereClause
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?";

try {
    $stmt = $db->prepare($sql);
    $paginationParams = array_merge($params, [$itemsPerPage, $offset]);
    $stmt->execute($paginationParams);
    $faculty = $stmt->fetchAll();
    if ($faculty === false) {
        $faculty = [];
    }
} catch (PDOException $e) {
    error_log("Faculty query error: " . $e->getMessage() . " | SQL: " . $sql);
    $faculty = [];
    // Display error in development
    if (ini_get('display_errors')) {
        echo "<div style='background: #ffeb3b; color: #000; padding: 15px; margin: 20px; border-radius: 5px; border-left: 4px solid #ff9800;'>";
        echo "<strong>Database Query Error:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
    }
} catch (Exception $e) {
    error_log("Faculty query exception: " . $e->getMessage());
    $faculty = [];
}

// Get specific faculty for editing
$editFaculty = null;
if ($facultyId) {
    $stmt = $db->prepare("SELECT u.*, 
                          fp.id as profile_id, fp.employee_id, fp.department, fp.position, 
                          fp.employment_status, fp.hire_date, fp.phone, fp.address, 
                          fp.emergency_contact_name, fp.emergency_contact_phone, 
                          fp.profile_picture, fp.employment_type,
                          fp.created_at as profile_created_at, fp.updated_at as profile_updated_at,
                          ps.salary_grade, ps.annual_salary 
                          FROM users u 
                          LEFT JOIN faculty_profiles fp ON u.id = fp.user_id 
                          LEFT JOIN position_salary ps ON fp.position = ps.position_title
                          WHERE u.id = ? AND u.user_type IN ('faculty','staff')");
    $stmt->execute([$facultyId]);
    $editFaculty = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    try {
        require_once '../includes/admin_layout_helper.php';
        admin_page_head('Faculty and Staff Management', 'Manage faculty and staff accounts, profiles, and information');
    } catch (Throwable $e) {
        die("<h1>Error Loading Page Head</h1><p>" . htmlspecialchars($e->getMessage()) . "</p><p>File: " . htmlspecialchars($e->getFile()) . "</p><p>Line: " . $e->getLine() . "</p>");
    }
    ?>
</head>
<body class="layout-admin">
    <?php 
    try {
        require_once '../includes/navigation.php';
        include_navigation();
    } catch (Throwable $e) {
        die("<h1>Error Loading Navigation</h1><p>" . htmlspecialchars($e->getMessage()) . "</p><p>File: " . htmlspecialchars($e->getFile()) . "</p><p>Line: " . $e->getLine() . "</p>");
    }
    ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <style>
                #facultyTableWrap .btn-group .btn { white-space: nowrap; }
                </style>
                <?php
                admin_page_header(
                    'Employee Management',
                    '',
                    'fas fa-users',
                    [
                        
                    ],
                    '<button type="button" class="btn btn-success me-2" onclick="showResendCredentialsModal()"><i class="fas fa-envelope me-1"></i>Resend Credentials</button>' .
                    '<a href="regenerate_qrcodes.php" class="btn btn-outline-secondary me-2"><i class="fas fa-qrcode me-1"></i>Regenerate QR Codes</a>' .
                    '<a href="create_faculty.php" class="btn btn-primary"><i class="fas fa-user-plus me-1"></i>Create Account</a>'
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Faculty List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2 text-primary"></i> Employee Members
                            <span class="badge bg-primary ms-2" id="facultyTotalBadge"><?php echo $totalCount; ?> Total</span>
                            <span class="badge bg-info ms-2" id="facultyPageBadge" style="<?php echo $totalPages <= 1 ? 'display:none;' : ''; ?>">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 mb-3" id="filterForm" action="faculty.php">
                            <input type="hidden" name="page" value="1" id="pageInput">
                            <div class="col-md-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="department" class="form-label">Department</label>
                                <select class="form-control" id="department" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo $departmentFilter === $dept['name'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Search by name or email">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-primary" id="filterSubmitBtn" title="Apply filters">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <a href="faculty.php" class="btn btn-outline-secondary" title="Clear filters">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            </div>
                        </form>
                        <div id="facultyListLoading" class="text-center py-4" style="display: none;">
                            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                            <p class="mt-2 text-muted mb-0">Loading employees...</p>
                        </div>
                        <div id="facultyListContent">
                            <div class="empty-state" id="facultyEmptyState" style="<?php echo empty($faculty) ? '' : 'display:none;'; ?>">
                                <i class="fas fa-users"></i>
                                <span class="empty-title">No Faculty & Staff Found</span>
                                <p class="mb-0">No faculty & staff members match your current filters.</p>
                            </div>
                            <div class="table-responsive" id="facultyTableWrap" style="overflow-x: auto; -webkit-overflow-scrolling: touch; <?php echo empty($faculty) ? 'display:none;' : ''; ?>">
                                <table class="table align-middle" style="min-width: 1000px;">
                                    <thead>
                                        <tr>
                                            <th>Safe Employee ID</th>
                                            <th>Name</th>
                                            <th class="d-none d-lg-table-cell">Email</th>
                                            <th class="d-none d-md-table-cell">Department</th>
                                            <th class="d-none d-lg-table-cell">Position</th>
                                            <th class="d-none d-xl-table-cell">Salary Grade</th>
                                            <th>Status</th>
                                            <th class="d-none d-xl-table-cell">Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="facultyTableBody">
                                        <?php foreach ($faculty as $member): ?>
                                            <tr>
                                                <td data-label="Safe Employee ID">
                                                    <?php if ($member['employee_id']): ?>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars($member['employee_id']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Not assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Name">
                                                    <strong><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                                    <div class="text-muted small d-lg-none">
                                                        <?php echo htmlspecialchars($member['email']); ?>
                                                        <?php if ($member['department']): ?>
                                                            <br><?php echo htmlspecialchars($member['department']); ?>
                                                        <?php endif; ?>
                                                        <?php if ($member['position']): ?>
                                                            <br><?php echo htmlspecialchars($member['position']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="d-none d-lg-table-cell"><?php echo htmlspecialchars($member['email']); ?></td>
                                                <td class="d-none d-md-table-cell" data-label="Department"><?php echo htmlspecialchars($member['department'] ?: 'Not specified'); ?></td>
                                                <td class="d-none d-lg-table-cell" data-label="Position">
                                                    <?php if ($member['position']): ?>
                                                        <strong><?php echo htmlspecialchars($member['position']); ?></strong>
                                                        <?php if ($member['salary_grade']): ?>
                                                            <br><small class="text-muted">SG-<?php echo htmlspecialchars($member['salary_grade']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not specified</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-xl-table-cell" data-label="Salary Grade">
                                                    <?php if ($member['salary_grade']): ?>
                                                        <span class="badge bg-info">SG-<?php echo htmlspecialchars($member['salary_grade']); ?></span>
                                                        <br><small class="text-muted">₱<?php echo number_format($member['annual_salary'], 2); ?>/monthly</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Status">
                                                    <span class="badge bg-<?php echo $member['is_active'] ? 'success' : 'danger'; ?>">
                                                        <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td class="d-none d-xl-table-cell" data-label="Created">
                                                    <small><?php echo formatDate($member['created_at'], 'M j, Y'); ?></small>
                                                </td>
                                                <td data-label="Actions">
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="Actions">
                                                        <button type="button" class="btn btn-outline-info" onclick="viewFaculty(<?php echo $member['id']; ?>)" title="View"><i class="fas fa-eye"></i></button>
                                                        <a href="edit_faculty.php?id=<?php echo $member['id']; ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                                        <button type="button" class="btn btn-outline-secondary" onclick="resendCredentials(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($member['email'], ENT_QUOTES); ?>')" title="Resend credentials"><i class="fas fa-envelope"></i></button>
                                                        <?php if (isSuperAdmin()): ?>
                                                        <button type="button" class="btn btn-outline-<?php echo $member['is_active'] ? 'warning' : 'success'; ?>" onclick="toggleFacultyStatus(<?php echo $member['id']; ?>, <?php echo $member['is_active'] ? 'false' : 'true'; ?>)" title="<?php echo $member['is_active'] ? 'Deactivate' : 'Activate'; ?>"><i class="fas fa-<?php echo $member['is_active'] ? 'ban' : 'check'; ?>"></i></button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="deleteFaculty(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name'], ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($totalPages > 1): ?>
                                <?php
                                $queryParams = [];
                                if ($statusFilter) $queryParams['status'] = $statusFilter;
                                if ($departmentFilter) $queryParams['department'] = $departmentFilter;
                                if ($search) $queryParams['search'] = $search;
                                $queryString = !empty($queryParams) ? '&amp;' . htmlspecialchars(http_build_query($queryParams), ENT_QUOTES, 'UTF-8') : '';
                                ?>
                                <div id="facultyPaginationWrap">
                                <nav aria-label="Faculty pagination" class="mt-4">
                                    <ul class="pagination justify-content-center mb-0">
                                        <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $currentPage - 1; ?><?php echo $queryString; ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                                <span class="sr-only">Previous</span>
                                            </a>
                                        </li>
                                        
                                        <?php
                                        // Show page numbers (max 5 pages around current)
                                        $startPage = max(1, $currentPage - 2);
                                        $endPage = min($totalPages, $currentPage + 2);
                                        
                                        // Always show first page if not in range
                                        if ($startPage > 1) {
                                            echo '<li class="page-item"><a class="page-link" href="?page=1' . $queryString . '">1</a></li>';
                                            if ($startPage > 2) {
                                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                            }
                                        }
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++) {
                                            $activeClass = $i == $currentPage ? 'active' : '';
                                            echo '<li class="page-item ' . $activeClass . '">';
                                            echo '<a class="page-link" href="?page=' . $i . $queryString . '">' . $i . '</a>';
                                            echo '</li>';
                                        }
                                        
                                        // Always show last page if not in range
                                        if ($endPage < $totalPages) {
                                            if ($endPage < $totalPages - 1) {
                                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                            }
                                            echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . $queryString . '">' . $totalPages . '</a></li>';
                                        }
                                        ?>
                                        
                                        <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $currentPage + 1; ?><?php echo $queryString; ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                                <span class="sr-only">Next</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="text-center mt-2">
                                        <small class="text-muted">
                                            Showing <?php echo $totalCount > 0 ? ($offset + 1) : 0; ?>-<?php echo min($offset + $itemsPerPage, $totalCount); ?> of <?php echo $totalCount; ?> employees
                                        </small>
                                    </div>
                                </nav>
                                </div>
                            <?php else: ?>
                                <div id="facultyPaginationWrap"><div class="text-center mt-3"><small class="text-muted">Showing all <?php echo $totalCount; ?> employee(s)</small></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Faculty Details Modal -->
    <div class="modal fade" id="facultyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Faculty & Staff Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="facultyDetails">
                    <!-- Faculty details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="qrCodeModalLabel">
                        <i class="fas fa-qrcode me-2"></i>Employee QR Code
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="text-muted mb-3">Scan this QR code for attendance tracking</p>
                    <div class="d-flex justify-content-center align-items-center mb-3">
                        <div class="p-4 bg-light rounded border" id="qrCodeModalImageContainer" style="max-width: 100%; width: fit-content;">
                            <img id="qrCodeModalImage" 
                                 src="" 
                                 alt="QR Code" 
                                 class="img-fluid" 
                                 style="max-width: 100%; height: auto; display: block; min-width: 300px;"
                                 loading="lazy"
                                 onerror="handleQRCodeError(this);">
                        </div>
                    </div>
                    <div class="mb-3">
                        <p class="mb-1"><strong>Name:</strong> <span id="qrCodeModalName"></span></p>
                        <p class="mb-0"><strong>Safe Employee ID:</strong> <span id="qrCodeModalEmployeeId"></span></p>
                    </div>
                    <div>
                        <a id="qrCodeDownloadLink" 
                           href="#" 
                           class="btn btn-primary">
                            <i class="fas fa-download me-2"></i>Download QR Code
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Account Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="statusForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="faculty_id" id="statusFacultyId">
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active">
                            <label class="form-check-label" for="is_active">
                                Active (can login and use system)
                            </label>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Inactive faculty members cannot login to the system.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Resend Credentials Modal -->
    <div class="modal fade" id="resendCredentialsModal" tabindex="-1" aria-labelledby="resendCredentialsModalLabel" aria-modal="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="resendCredentialsModalLabel">
                        <i class="fas fa-envelope me-2"></i>Resend Login Credentials
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> A new password will be generated and sent to the selected employee(s). The old password will no longer work.
                    </div>
                    
                    <div class="mb-3">
                        <label for="employeeSearchFilter" class="form-label"><strong>Search Employees:</strong></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="employeeSearchFilter" 
                                   placeholder="Search by name, email, or Safe Employee ID..." 
                                   onkeyup="filterEmployeeTable(this.value)">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('employeeSearchFilter').value=''; filterEmployeeTable('');" title="Clear search">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <small class="text-muted" id="employeeSearchCount"></small>
                    </div>
                    
                    <div id="employeeTableLoading" class="text-center py-4">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Loading employees...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading all employees from database...</p>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto; display: none;" id="employeeTableContainer">
                        <table class="table table-sm table-hover" id="employeeTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" id="selectAllEmployees" onchange="toggleAllEmployees(this)">
                                    </th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Safe Employee ID</th>
                                </tr>
                            </thead>
                            <tbody id="employeeTableBody">
                                <!-- Employees will be loaded here via AJAX -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="employeeTableError" class="alert alert-danger" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="employeeTableErrorMessage">Failed to load employees. Please try again.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <span id="selectedCount" class="me-auto text-muted"></span>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="confirmResendBtn" onclick="performResendCredentials()">
                        <i class="fas fa-envelope me-1"></i>Resend Credentials
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-modal="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete Account
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    
                    <p class="mb-3">You are about to permanently delete the following faculty account:</p>
                    
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title mb-2">
                                <i class="fas fa-user me-2"></i>
                                <span id="deleteFacultyName"></span>
                            </h6>
                            <p class="card-text mb-0">
                                <small class="text-muted">Faculty ID: <span id="deleteFacultyId"></span></small>
                            </p>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <p class="mb-2"><strong>The following data will be permanently deleted:</strong></p>
                        <ul class="mb-0">
                            <li>Faculty account and profile information</li>
                            <li>Personal Data Sheet (PDS) records</li>
                            <li>All requirement submissions and uploaded files</li>
                            <li>Notifications and activity logs</li>
                            <li>Profile picture and associated files</li>
                        </ul>
                    </div>
                    
                    <p class="text-danger mt-3 mb-0">
                        <strong>This action is permanent and cannot be reversed!</strong>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>Delete Permanently
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script>
        // Define handleQRCodeError early to ensure it's available when images load
        function handleQRCodeError(img) {
            const container = document.getElementById('qrCodeModalImageContainer');
            if (container) {
                container.innerHTML = '<div style="padding:2rem;color:#999;"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>QR Code image could not be loaded</p><p class="small text-muted">Please try refreshing the page or contact support if the issue persists.</p></div>';
            }
        }

        // Handle QR code image errors in the faculty details modal
        function handleQRCodeImageError(img) {
            img.style.display = 'none';
            const container = img.parentElement;
            if (container) {
                container.innerHTML = '<div style="padding:20px;color:#999;"><i class="fas fa-qrcode fa-3x"></i><br><small>QR Code not available</small></div>';
            }
        }

        let deleteModalInstance;
        let facultyToDelete = null;

        document.addEventListener('DOMContentLoaded', function() {
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteModal'));
            
            // Handle delete confirmation
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                if (facultyToDelete) {
                    performDelete(facultyToDelete);
                }
            });

            // Real-time filtering (no page reload)
            const filterForm = document.getElementById('filterForm');
            const filterSubmitBtn = document.getElementById('filterSubmitBtn');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) { e.preventDefault(); loadFacultyList(1); return false; });
                filterSubmitBtn.addEventListener('click', function() { loadFacultyList(1); });
                document.getElementById('status').addEventListener('change', function() { loadFacultyList(1); });
                document.getElementById('department').addEventListener('change', function() { loadFacultyList(1); });
                let searchDebounce;
                document.getElementById('search').addEventListener('input', function() {
                    clearTimeout(searchDebounce);
                    searchDebounce = setTimeout(function() { loadFacultyList(1); }, 300);
                });
            }
        });

        function formatFacultyDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        const isSuperAdmin = <?php echo isSuperAdmin() ? 'true' : 'false'; ?>;
        function buildFacultyRow(member) {
            const fullName = (member.first_name || '') + ' ' + (member.last_name || '');
            const esc = function(s) { return (s == null ? '' : String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); };
            const empId = member.employee_id ? '<span class="badge bg-primary">' + esc(member.employee_id) + '</span>' : '<span class="text-muted small">Not assigned</span>';
            const dept = member.department ? esc(member.department) : 'Not specified';
            const pos = member.position ? '<strong>' + esc(member.position) + '</strong>' + (member.salary_grade ? '<br><small class="text-muted">SG-' + esc(member.salary_grade) + '</small>' : '') : '<span class="text-muted">Not specified</span>';
            const sg = member.salary_grade ? '<span class="badge bg-info">SG-' + esc(member.salary_grade) + '</span><br><small class="text-muted">₱' + (parseFloat(member.annual_salary) || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '/monthly</small>' : '<span class="text-muted">-</span>';
            const statusBadge = member.is_active ? 'success' : 'danger';
            const statusText = member.is_active ? 'Active' : 'Inactive';
            const toggleTitle = member.is_active ? 'Deactivate' : 'Activate';
            const toggleIcon = member.is_active ? 'ban' : 'check';
            const fullNameEsc = esc(fullName).replace(/'/g, "\\'");
            const emailEsc = esc(member.email).replace(/'/g, "\\'");
            const toggleBtnClass = member.is_active ? 'warning' : 'success';
            let actionBtns = '<button type="button" class="btn btn-outline-info" onclick="viewFaculty(' + member.id + ')" title="View"><i class="fas fa-eye"></i></button>' +
                '<a href="edit_faculty.php?id=' + member.id + '" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>' +
                '<button type="button" class="btn btn-outline-secondary" onclick="resendCredentials(' + member.id + ', \'' + fullNameEsc + '\', \'' + emailEsc + '\')" title="Resend credentials"><i class="fas fa-envelope"></i></button>';
            if (isSuperAdmin) {
                actionBtns += '<button type="button" class="btn btn-outline-' + toggleBtnClass + '" onclick="toggleFacultyStatus(' + member.id + ', ' + (member.is_active ? 'false' : 'true') + ')" title="' + toggleTitle + '"><i class="fas fa-' + toggleIcon + '"></i></button>' +
                    '<button type="button" class="btn btn-outline-danger" onclick="deleteFaculty(' + member.id + ', \'' + fullNameEsc + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
            }
            const actionsCell = '<td data-label="Actions"><div class="btn-group btn-group-sm" role="group" aria-label="Actions">' + actionBtns + '</div></td>';
            return '<tr><td data-label="Safe Employee ID">' + empId + '</td><td data-label="Name"><strong>' + esc(fullName) + '</strong><div class="text-muted small d-lg-none">' + esc(member.email) + (member.department ? '<br>' + esc(member.department) : '') + (member.position ? '<br>' + esc(member.position) : '') + '</div></td><td class="d-none d-lg-table-cell">' + esc(member.email) + '</td><td class="d-none d-md-table-cell" data-label="Department">' + dept + '</td><td class="d-none d-lg-table-cell" data-label="Position">' + pos + '</td><td class="d-none d-xl-table-cell" data-label="Salary Grade">' + sg + '</td><td data-label="Status"><span class="badge bg-' + statusBadge + '">' + statusText + '</span></td><td class="d-none d-xl-table-cell" data-label="Created"><small>' + formatFacultyDate(member.created_at) + '</small></td>' + actionsCell + '</tr>';
        }

        function buildPaginationHtml(data) {
            const q = new URLSearchParams();
            if (document.getElementById('status').value) q.set('status', document.getElementById('status').value);
            if (document.getElementById('department').value) q.set('department', document.getElementById('department').value);
            const searchVal = document.getElementById('search').value.trim();
            if (searchVal) q.set('search', searchVal);
            const qs = q.toString() ? '&' + q.toString() : '';
            const cp = data.currentPage, tp = data.totalPages, tot = data.totalCount, pp = data.itemsPerPage;
            const offset = (cp - 1) * pp;
            if (tp <= 1) {
                return '<div class="text-center mt-3"><small class="text-muted">Showing all ' + tot + ' employee(s)</small></div>';
            }
            let html = '<nav aria-label="Faculty pagination" class="mt-4"><ul class="pagination justify-content-center mb-0">';
            html += '<li class="page-item ' + (cp <= 1 ? 'disabled' : '') + '"><a class="page-link" href="javascript:void(0)" data-page="' + (cp - 1) + '" aria-label="Previous"><span aria-hidden="true">&laquo;</span><span class="sr-only">Previous</span></a></li>';
            const startPage = Math.max(1, cp - 2), endPage = Math.min(tp, cp + 2);
            if (startPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="javascript:void(0)" data-page="1">1</a></li>';
                if (startPage > 2) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            for (let i = startPage; i <= endPage; i++) {
                html += '<li class="page-item ' + (i === cp ? 'active' : '') + '"><a class="page-link" href="javascript:void(0)" data-page="' + i + '">' + i + '</a></li>';
            }
            if (endPage < tp) {
                if (endPage < tp - 1) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                html += '<li class="page-item"><a class="page-link" href="javascript:void(0)" data-page="' + tp + '">' + tp + '</a></li>';
            }
            html += '<li class="page-item ' + (cp >= tp ? 'disabled' : '') + '"><a class="page-link" href="javascript:void(0)" data-page="' + (cp + 1) + '" aria-label="Next"><span aria-hidden="true">&raquo;</span><span class="sr-only">Next</span></a></li>';
            html += '</ul><div class="text-center mt-2"><small class="text-muted">Showing ' + (tot ? (offset + 1) : 0) + '-' + Math.min(offset + pp, tot) + ' of ' + tot + ' employees</small></div></nav>';
            return html;
        }

        function loadFacultyList(page) {
            page = page || 1;
            const status = document.getElementById('status').value;
            const department = document.getElementById('department').value;
            const search = document.getElementById('search').value.trim();
            const params = new URLSearchParams({ page: page });
            if (status) params.set('status', status);
            if (department) params.set('department', department);
            if (search) params.set('search', search);
            const listContent = document.getElementById('facultyListContent');
            const loadingEl = document.getElementById('facultyListLoading');
            const emptyEl = document.getElementById('facultyEmptyState');
            const tableWrap = document.getElementById('facultyTableWrap');
            const tbody = document.getElementById('facultyTableBody');
            const totalBadge = document.getElementById('facultyTotalBadge');
            const pageBadge = document.getElementById('facultyPageBadge');
            let paginationWrap = document.getElementById('facultyPaginationWrap');
            if (!paginationWrap && listContent) {
                paginationWrap = document.createElement('div');
                paginationWrap.id = 'facultyPaginationWrap';
                listContent.appendChild(paginationWrap);
            }
            if (loadingEl) loadingEl.style.display = 'block';
            if (listContent) listContent.style.opacity = '0.6';
            fetch('get_faculty_list.php?' + params.toString())
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (loadingEl) loadingEl.style.display = 'none';
                    if (listContent) listContent.style.opacity = '';
                    if (!data.success) { return; }
                    const faculty = data.faculty || [];
                    const totalCount = data.totalCount || 0;
                    const totalPages = data.totalPages || 0;
                    const currentPage = data.currentPage || 1;
                    if (totalBadge) totalBadge.textContent = totalCount + ' Total';
                    if (pageBadge) {
                        pageBadge.style.display = totalPages > 1 ? '' : 'none';
                        pageBadge.textContent = 'Page ' + currentPage + ' of ' + totalPages;
                    }
                    if (faculty.length === 0) {
                        if (emptyEl) emptyEl.style.display = '';
                        if (tableWrap) tableWrap.style.display = 'none';
                        if (tbody) tbody.innerHTML = '';
                        if (paginationWrap) paginationWrap.innerHTML = '';
                    } else {
                        if (emptyEl) emptyEl.style.display = 'none';
                        if (tableWrap) tableWrap.style.display = '';
                        if (tbody) {
                            tbody.innerHTML = faculty.map(buildFacultyRow).join('');
                        }
                        if (paginationWrap) {
                            paginationWrap.innerHTML = buildPaginationHtml(data);
                            paginationWrap.querySelectorAll('.page-link[data-page]').forEach(function(a) {
                                a.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    const p = parseInt(a.getAttribute('data-page'), 10);
                                    if (!isNaN(p) && p >= 1) loadFacultyList(p);
                                });
                            });
                        }
                    }
                    if (history.replaceState) {
                        const url = 'faculty.php?' + params.toString();
                        history.replaceState({}, '', url);
                    }
                })
                .catch(function() {
                    if (loadingEl) loadingEl.style.display = 'none';
                    if (listContent) listContent.style.opacity = '';
                });
        }

        function viewFaculty(facultyId) {
            fetch(`get_faculty.php?id=${facultyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const faculty = data.faculty;
                        const qrCodeHtml = faculty.qr_code_url ? `
                            <div class="text-center mb-3">
                                <h6>Employee QR Code</h6>
                                <div class="d-inline-block p-3 bg-light rounded border qr-code-clickable" 
                                     style="max-width: 200px; cursor: pointer; transition: transform 0.2s;"
                                     data-bs-toggle="modal" 
                                     data-bs-target="#qrCodeModal"
                                     data-qr-url="${faculty.qr_code_url}"
                                     data-first-name="${(faculty.first_name || '').replace(/"/g, '&quot;')}"
                                     data-last-name="${(faculty.last_name || '').replace(/"/g, '&quot;')}"
                                     data-employee-id="${(faculty.employee_id || '').replace(/"/g, '&quot;')}"
                                     role="button"
                                     tabindex="0"
                                     aria-label="View QR Code"
                                     onmouseover="this.style.transform='scale(1.05)'"
                                     onmouseout="this.style.transform='scale(1)'"
                                     title="Click to view full-size QR code">
                                    <img src="${faculty.qr_code_url}" 
                                         alt="QR Code" 
                                         class="img-fluid qr-code-img" 
                                         style="max-width: 100%; pointer-events: none;"
                                         loading="lazy"
                                         onerror="handleQRCodeImageError(this);">
                                </div>
                                ${faculty.employee_id ? `<p class="mt-2 mb-0"><small class="text-muted">Safe Employee ID: <strong>${faculty.employee_id}</strong></small></p>` : ''}
                                <p class="mt-1 mb-0"><small class="text-muted"><i class="fas fa-info-circle"></i> Click QR code to view full size</small></p>
                            </div>
                        ` : faculty.employee_id ? `
                            <div class="text-center mb-3">
                                <h6>Employee QR Code</h6>
                                <div class="d-inline-block p-3 bg-light rounded border" style="max-width: 200px;">
                                    <div style="padding:20px;color:#999;">
                                        <i class="fas fa-qrcode fa-3x"></i>
                                        <br><small>QR Code not available</small>
                                    </div>
                                </div>
                                <p class="mt-2 mb-0"><small class="text-muted">Safe Employee ID: <strong>${faculty.employee_id}</strong></small></p>
                            </div>
                        ` : '';
                        
                        document.getElementById('facultyDetails').innerHTML = `
                            ${qrCodeHtml}
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Personal Information</h6>
                                    <p><strong>Name:</strong> ${faculty.first_name} ${faculty.last_name}</p>
                                    <p><strong>Email:</strong> ${faculty.email}</p>
                                    <p><strong>Status:</strong> 
                                        <span class="badge badge-${faculty.is_active ? 'success' : 'danger'}">
                                            ${faculty.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <h6>Profile Information</h6>
                                    <p><strong>Department:</strong> ${faculty.department || 'Not specified'}</p>
                                    <p><strong>Position:</strong> ${faculty.position || 'Not specified'}</p>
                                    ${faculty.salary_grade ? `<p><strong>Salary Grade:</strong> <span class="badge bg-info">SG-${faculty.salary_grade}</span></p>` : ''}
                                    ${faculty.annual_salary ? `<p><strong>Annual Salary:</strong> ₱${parseFloat(faculty.annual_salary).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>` : ''}
                                    ${faculty.annual_salary ? `<p><strong>Monthly Salary:</strong> ₱${(parseFloat(faculty.annual_salary)/12).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>` : ''}
                                    <p><strong>Employment Status:</strong> ${faculty.employment_status || 'Not specified'}</p>
                                    <p><strong>Hire Date:</strong> ${faculty.hire_date || 'Not specified'}</p>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-12">
                                    <h6>Account Information</h6>
                                    <p><strong>Registered:</strong> ${faculty.created_at}</p>
                                    <p><strong>Last Updated:</strong> ${faculty.updated_at}</p>
                                </div>
                            </div>
                        `;
                        new bootstrap.Modal(document.getElementById('facultyModal')).show();
                    } else {
                        showError('Failed to load faculty details.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Failed to load faculty details.');
                });
        }
        
        function toggleFacultyStatus(facultyId, newStatus) {
            document.getElementById('statusFacultyId').value = facultyId;
            document.getElementById('is_active').checked = newStatus === 'true';
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }
        
        function deleteFaculty(facultyId, facultyName) {
            facultyToDelete = facultyId;
            document.getElementById('deleteFacultyName').textContent = facultyName;
            document.getElementById('deleteFacultyId').textContent = facultyId;
            deleteModalInstance.show();
        }

        function performDelete(facultyId) {
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            const originalText = confirmBtn.innerHTML;
            
            // Disable button and show loading state
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
            
            const formData = new FormData();
            formData.append('faculty_id', facultyId);
            
            fetch('delete_faculty.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    deleteModalInstance.hide();
                    
                    // Show success message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    document.querySelector('.main-content').insertBefore(
                        alertDiv, 
                        document.querySelector('.main-content').firstChild.nextSibling
                    );
                    
                    // Reload page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    alert('Error: ' + data.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the faculty account.');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = originalText;
            });
        }
        
        // Handle QR code modal content update when modal is shown
        document.addEventListener('DOMContentLoaded', function() {
            const qrCodeModal = document.getElementById('qrCodeModal');
            if (qrCodeModal) {
                qrCodeModal.addEventListener('show.bs.modal', function(event) {
                    // Get the button that triggered the modal
                    const button = event.relatedTarget;
                    if (!button) return;
                    
                    // Get data attributes from the clicked QR code container
                    const qrUrl = button.getAttribute('data-qr-url');
                    const firstName = button.getAttribute('data-first-name') || '';
                    const lastName = button.getAttribute('data-last-name') || '';
                    const employeeId = button.getAttribute('data-employee-id') || '';
                    
                    // Decode HTML entities
                    const decodedFirstName = firstName.replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                    const decodedLastName = lastName.replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                    const decodedEmployeeId = employeeId.replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                    
                    // Get modal elements
                    const qrImage = document.getElementById('qrCodeModalImage');
                    const qrImageContainer = document.getElementById('qrCodeModalImageContainer');
                    const qrName = document.getElementById('qrCodeModalName');
                    const qrEmployeeId = document.getElementById('qrCodeModalEmployeeId');
                    const qrDownloadLink = document.getElementById('qrCodeDownloadLink');
                    
                    // Reset container HTML if it was replaced by error message
                    if (qrImageContainer && !qrImageContainer.querySelector('img')) {
                        qrImageContainer.innerHTML = '<img id="qrCodeModalImage" src="" alt="QR Code" class="img-fluid" style="max-width: 100%; height: auto; display: block; min-width: 300px;" loading="lazy" onerror="handleQRCodeError(this);">';
                    }
                    
                    // Get the image element again (might have been recreated)
                    const qrImageElement = document.getElementById('qrCodeModalImage');
                    
                    if (qrImageElement && qrUrl) {
                        // Set QR code image source
                        qrImageElement.src = qrUrl;
                        qrImageElement.style.display = 'block';
                    }
                    
                    if (qrName) {
                        // Set name
                        qrName.textContent = `${decodedLastName || ''}, ${decodedFirstName || ''}`.trim() || 'Not specified';
                    }
                    
                    if (qrEmployeeId) {
                        // Set employee ID
                        qrEmployeeId.textContent = decodedEmployeeId || 'Not assigned';
                    }
                    
                    if (qrDownloadLink && qrUrl) {
                        // Set download link - add download parameter to the URL
                        const separator = qrUrl.includes('?') ? '&' : '?';
                        const downloadUrl = qrUrl + separator + 'download=1';
                        qrDownloadLink.href = downloadUrl;
                    }
                });
            }
        });
        
        function exportFaculty() {
            // Simple CSV export
            const table = document.querySelector('table');
            const rows = Array.from(table.querySelectorAll('tr'));
            const csvContent = rows.map(row => {
                const cells = Array.from(row.querySelectorAll('th, td'));
                return cells.map(cell => `"${cell.textContent.trim()}"`).join(',');
            }).join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'faculty_export.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        let allEmployees = [];
        let selectedEmployeeIds = new Set();

        function updateSelectedCount() {
            const countEl = document.getElementById('selectedCount');
            if (countEl) {
                const count = selectedEmployeeIds.size;
                countEl.textContent = count > 0 ? `${count} account(s) selected` : '';
                countEl.className = count > 0 ? 'me-auto text-success fw-semibold' : 'me-auto text-muted';
            }
        }

        function updateSelectAllState() {
            const selectAllCheckbox = document.getElementById('selectAllEmployees');
            if (!selectAllCheckbox) return;
            const visibleCbs = Array.from(document.querySelectorAll('#employeeTableBody .employee-row'))
                .filter(row => row.style.display !== 'none')
                .map(row => row.querySelector('.employee-checkbox'))
                .filter(Boolean);
            const checkedVisible = visibleCbs.filter(cb => selectedEmployeeIds.has(cb.value));
            selectAllCheckbox.checked = visibleCbs.length > 0 && checkedVisible.length === visibleCbs.length;
            selectAllCheckbox.indeterminate = checkedVisible.length > 0 && checkedVisible.length < visibleCbs.length;
        }
        
        function showResendCredentialsModal() {
            const modal = new bootstrap.Modal(document.getElementById('resendCredentialsModal'));
            
            // Reset modal state
            selectedEmployeeIds.clear();
            updateSelectedCount();
            document.getElementById('employeeSearchFilter').value = '';
            document.getElementById('employeeTableContainer').style.display = 'none';
            document.getElementById('employeeTableError').style.display = 'none';
            document.getElementById('employeeTableLoading').style.display = 'block';
            document.getElementById('employeeTableBody').innerHTML = '';
            
            // Load all employees from database
            loadAllEmployees();
            
            modal.show();
        }
        
        function loadAllEmployees() {
            fetch('get_all_employees.php')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('employeeTableLoading').style.display = 'none';
                    
                    if (data.success && data.employees) {
                        allEmployees = data.employees;
                        populateEmployeeTable(allEmployees);
                        document.getElementById('employeeTableContainer').style.display = 'block';
                        document.getElementById('employeeSearchCount').textContent = `Total: ${data.count} employee(s)`;
                    } else {
                        document.getElementById('employeeTableError').style.display = 'block';
                        document.getElementById('employeeTableErrorMessage').textContent = data.message || 'Failed to load employees.';
                    }
                })
                .catch(error => {
                    console.error('Error loading employees:', error);
                    document.getElementById('employeeTableLoading').style.display = 'none';
                    document.getElementById('employeeTableError').style.display = 'block';
                    document.getElementById('employeeTableErrorMessage').textContent = 'An error occurred while loading employees. Please try again.';
                });
        }
        
        function populateEmployeeTable(employees) {
            const tbody = document.getElementById('employeeTableBody');
            tbody.innerHTML = '';
            
            employees.forEach(employee => {
                const row = document.createElement('tr');
                row.className = 'employee-row';
                row.setAttribute('data-name', (employee.full_name || '').toLowerCase());
                row.setAttribute('data-email', (employee.email || '').toLowerCase());
                row.setAttribute('data-employee-id', (employee.employee_id || 'n/a').toLowerCase());
                
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="employee-checkbox" value="${employee.id}" 
                               data-name="${escapeHtml(employee.full_name)}"
                               data-email="${escapeHtml(employee.email)}">
                    </td>
                    <td>${escapeHtml(employee.full_name)}</td>
                    <td>${escapeHtml(employee.email)}</td>
                    <td>${escapeHtml(employee.employee_id)}</td>
                `;

                const cb = row.querySelector('.employee-checkbox');
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        selectedEmployeeIds.add(this.value);
                    } else {
                        selectedEmployeeIds.delete(this.value);
                    }
                    updateSelectAllState();
                    updateSelectedCount();
                });
                
                tbody.appendChild(row);
            });
            
            // Update search count
            filterEmployeeTable(document.getElementById('employeeSearchFilter').value);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function toggleAllEmployees(checkbox) {
            const visibleRows = Array.from(document.querySelectorAll('#employeeTableBody .employee-row'))
                .filter(row => row.style.display !== 'none');
            visibleRows.forEach(row => {
                const cb = row.querySelector('.employee-checkbox');
                if (cb) {
                    cb.checked = checkbox.checked;
                    if (checkbox.checked) {
                        selectedEmployeeIds.add(cb.value);
                    } else {
                        selectedEmployeeIds.delete(cb.value);
                    }
                }
            });
            updateSelectedCount();
        }
        
        function filterEmployeeTable(searchTerm) {
            const searchLower = searchTerm.toLowerCase().trim();
            const rows = document.querySelectorAll('#employeeTableBody .employee-row');
            let visibleCount = 0;
            const totalCount = rows.length;
            
            rows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const employeeId = row.getAttribute('data-employee-id') || '';
                
                const matches = !searchLower || 
                    name.includes(searchLower) || 
                    email.includes(searchLower) || 
                    employeeId.includes(searchLower);
                
                const checkbox = row.querySelector('.employee-checkbox');
                if (matches) {
                    row.style.display = '';
                    visibleCount++;
                    // Restore checkbox state from the persistent selection set
                    if (checkbox) checkbox.checked = selectedEmployeeIds.has(checkbox.value);
                } else {
                    row.style.display = 'none';
                    // Do NOT uncheck — selection is tracked in selectedEmployeeIds
                }
            });
            
            // Update search count
            const searchCountEl = document.getElementById('employeeSearchCount');
            if (searchCountEl) {
                if (searchTerm.trim()) {
                    searchCountEl.textContent = `Showing ${visibleCount} of ${totalCount} employee(s)`;
                } else {
                    searchCountEl.textContent = `Total: ${totalCount} employee(s)`;
                }
            }

            updateSelectAllState();
        }
        
        function resendCredentials(facultyId, facultyName, facultyEmail) {
            if (confirm(`Resend login credentials to ${facultyName} (${facultyEmail})? A new password will be generated.`)) {
                performResendCredentials([facultyId]);
            }
        }
        
        function performResendCredentials(employeeIds = null) {
            const confirmBtn = document.getElementById('confirmResendBtn');
            const originalText = confirmBtn.innerHTML;
            
            // Disable button and show loading state
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending...';
            
            let employeeIdsToSend = [];
            
            if (employeeIds) {
                // Single employee resend
                employeeIdsToSend = employeeIds;
            } else {
                // Use the persistent selection set — includes accounts selected across all searches
                if (selectedEmployeeIds.size === 0) {
                    alert('Please select at least one employee.');
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    return;
                }
                employeeIdsToSend = Array.from(selectedEmployeeIds);
            }
            
            const formData = new FormData();
            formData.append('employee_ids', JSON.stringify(employeeIdsToSend));
            
            fetch('resend_credentials.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('resendCredentialsModal'));
                    if (modal) modal.hide();
                    
                    // Reset confirm button
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                    
                    // Show success message (no page reload)
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="fas fa-check-circle me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    document.querySelector('.main-content').insertBefore(
                        alertDiv, 
                        document.querySelector('.main-content').firstChild.nextSibling
                    );
                    
                    // Auto-dismiss after 1.5 seconds (no page reload)
                    setTimeout(() => {
                        alertDiv.classList.remove('show');
                        alertDiv.classList.add('fade');
                        setTimeout(() => alertDiv.remove(), 150);
                    }, 1500);
                } else {
                    // Show error message
                    alert('Error: ' + data.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while sending credentials.');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = originalText;
            });
        }
    </script>
</body>
</html>







