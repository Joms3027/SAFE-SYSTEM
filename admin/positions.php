<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Check if step column exists (migration may not have been run yet)
$hasStepColumn = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM position_salary LIKE 'step'");
    $hasStepColumn = $colCheck && $colCheck->rowCount() > 0;
} catch (Exception $e) {
    // Ignore - assume no step column
}

// Helper function to build pagination URL
function buildPaginationUrl($page, $search = '', $salaryGrade = '') {
    $params = ['page' => $page];
    if ($search) {
        $params['search'] = $search;
    }
    if ($salaryGrade) {
        $params['salary_grade'] = $salaryGrade;
    }
    return 'positions.php?' . http_build_query($params);
}

// Get all positions with filters
$search = $_GET['search'] ?? '';
$salaryGradeFilter = $_GET['salary_grade'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE clause for count query (without table alias)
$countWhereClause = "1=1";
$params = [];

if ($search) {
    $countWhereClause .= " AND position_title LIKE ?";
    $params[] = "%$search%";
}

if ($salaryGradeFilter) {
    $countWhereClause .= " AND salary_grade = ?";
    $params[] = $salaryGradeFilter;
}

// Build WHERE clause for main query (with table alias)
$whereClause = "1=1";
$mainParams = [];

if ($search) {
    $whereClause .= " AND ps1.position_title LIKE ?";
    $mainParams[] = "%$search%";
}

if ($salaryGradeFilter) {
    $whereClause .= " AND ps1.salary_grade = ?";
    $mainParams[] = $salaryGradeFilter;
}

// Get total count for pagination
if ($hasStepColumn) {
    $countSql = "SELECT COUNT(*) as total FROM (
                 SELECT position_title, salary_grade, COALESCE(step, 1) as step
                 FROM position_salary 
                 WHERE $countWhereClause
                 GROUP BY position_title, salary_grade, COALESCE(step, 1)
                 ) x";
} else {
    $countSql = "SELECT COUNT(DISTINCT position_title) as total FROM position_salary WHERE $countWhereClause";
}
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get paginated positions
$limit = (int)$perPage;
$offsetValue = (int)$offset;
if ($hasStepColumn) {
    $sql = "SELECT ps1.* 
            FROM position_salary ps1
            INNER JOIN (
                SELECT position_title, salary_grade, COALESCE(step, 1) as step, MIN(id) as min_id
                FROM position_salary
                GROUP BY position_title, salary_grade, COALESCE(step, 1)
            ) ps2 ON ps1.position_title = ps2.position_title AND ps1.salary_grade = ps2.salary_grade AND COALESCE(ps1.step, 1) = ps2.step AND ps1.id = ps2.min_id
            WHERE $whereClause 
            ORDER BY ps1.position_title ASC, ps1.salary_grade ASC, COALESCE(ps1.step, 1) ASC
            LIMIT $limit OFFSET $offsetValue";
} else {
    $sql = "SELECT ps1.* 
            FROM position_salary ps1
            INNER JOIN (
                SELECT position_title, MIN(id) as min_id
                FROM position_salary
                GROUP BY position_title
            ) ps2 ON ps1.position_title = ps2.position_title AND ps1.id = ps2.min_id
            WHERE $whereClause 
            ORDER BY ps1.position_title ASC
            LIMIT $limit OFFSET $offsetValue";
}
$stmt = $db->prepare($sql);
$stmt->execute($mainParams);
$positions = $stmt->fetchAll();

// Get unique salary grades for filter
$gradesStmt = $db->query("SELECT DISTINCT salary_grade FROM position_salary ORDER BY salary_grade ASC");
$salaryGrades = $gradesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total_positions,
        AVG(annual_salary) as avg_salary,
        MAX(annual_salary) as max_salary,
        MIN(annual_salary) as min_salary
    FROM position_salary
");
$stats = $statsStmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Position & Salary Management', 'Manage positions and salary grades');
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
                    'Position & Salary Management',
                    '',
                    'fas fa-briefcase',
                    [
                        
                    ],
                    '<div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary" onclick="showBatchImportModal()"><i class="fas fa-file-csv me-1"></i>Batch Import</button><button type="button" class="btn btn-primary" onclick="showAddModal()"><i class="fas fa-plus me-1"></i>Add New Position</button></div>'
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Statistics Cards -->
               

                <!-- Positions List -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0" id="position-list-header">
                            <i class="fas fa-list me-2"></i>Position List 
                            <?php if ($totalRecords > 0): ?>
                                (Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalRecords); ?> of <?php echo $totalRecords; ?>)
                            <?php else: ?>
                                (0)
                            <?php endif; ?>
                        </h5>
                        <div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportPositions()">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Filters -->
                        <form method="GET" class="row g-3 mb-3" id="filterForm">
                            <div class="col-md-4">
                                <label for="salary_grade" class="form-label">Salary Grade</label>
                                <select class="form-control" id="salary_grade" name="salary_grade">
                                    <option value="">All Grades</option>
                                    <?php foreach ($salaryGrades as $grade): ?>
                                        <option value="<?php echo $grade; ?>" <?php echo $salaryGradeFilter == $grade ? 'selected' : ''; ?>>
                                            SG-<?php echo $grade; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="search" class="form-label">Search Position</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Search by position title">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-primary" id="searchBtn">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="clearBtn">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div id="positions-container">
                            <?php if (empty($positions)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-briefcase fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Positions Found</h5>
                                    <p class="text-muted">No positions match your current filters.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Position Title</th>
                                                <th>Salary Grade</th>
                                                <?php if ($hasStepColumn): ?><th>Step</th><?php endif; ?>
                                                <th>Annual Salary</th>
                                                <th>Monthly Salary</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="positions-tbody">
                                            <?php foreach ($positions as $position): ?>
                                                <tr>
                                                    <td><?php echo $position['id']; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($position['position_title']); ?></strong></td>
                                                    <td>
                                                        <span class="badge bg-info">SG-<?php echo $position['salary_grade']; ?></span>
                                                    </td>
                                                    <?php if ($hasStepColumn): ?><td><?php echo (int)($position['step'] ?? 1); ?></td><?php endif; ?>
                                                    <td>₱<?php echo number_format($position['annual_salary'] * 12, 2); ?></td>
                                                    <td>₱<?php echo number_format($position['annual_salary'], 2); ?></td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-info" 
                                                                    onclick='editPosition(<?php echo json_encode($position); ?>)' 
                                                                    title="Edit position">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger" 
                                                                    onclick="deletePosition(<?php echo $position['id']; ?>, '<?php echo htmlspecialchars($position['position_title']); ?>')" 
                                                                    title="Delete position">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <div id="pagination-container">
                                    <?php if ($totalPages > 1): ?>
                                        <nav aria-label="Page navigation" class="mt-4">
                                            <ul class="pagination justify-content-center">
                                                <!-- Previous Button -->
                                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="<?php echo buildPaginationUrl($page - 1, $search, $salaryGradeFilter); ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo; Previous</span>
                                                    </a>
                                                </li>
                                                
                                                <!-- Page Numbers -->
                                                <?php
                                                $startPage = max(1, $page - 2);
                                                $endPage = min($totalPages, $page + 2);
                                                
                                                if ($startPage > 1): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="<?php echo buildPaginationUrl(1, $search, $salaryGradeFilter); ?>">1</a>
                                                    </li>
                                                    <?php if ($startPage > 2): ?>
                                                        <li class="page-item disabled">
                                                            <span class="page-link">...</span>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                        <a class="page-link" href="<?php echo buildPaginationUrl($i, $search, $salaryGradeFilter); ?>">
                                                            <?php echo $i; ?>
                                                        </a>
                                                    </li>
                                                <?php endfor; ?>
                                                
                                                <?php if ($endPage < $totalPages): ?>
                                                    <?php if ($endPage < $totalPages - 1): ?>
                                                        <li class="page-item disabled">
                                                            <span class="page-link">...</span>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="<?php echo buildPaginationUrl($totalPages, $search, $salaryGradeFilter); ?>">
                                                            <?php echo $totalPages; ?>
                                                        </a>
                                                    </li>
                                                <?php endif; ?>
                                                
                                                <!-- Next Button -->
                                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="<?php echo buildPaginationUrl($page + 1, $search, $salaryGradeFilter); ?>" aria-label="Next">
                                                        <span aria-hidden="true">Next &raquo;</span>
                                                    </a>
                                                </li>
                                            </ul>
                                        </nav>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add/Edit Position Modal -->
    <div class="modal fade" id="positionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="positionForm">
                    <div class="modal-body">
                        <input type="hidden" id="position_id" name="position_id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="position_title" class="form-label">Position Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="position_title" name="position_title" required placeholder="e.g. Professor, Instructor">
                        </div>
                        
                        <div class="mb-3">
                            <label for="salary_grade_input" class="form-label">Salary Grade <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="salary_grade_input" name="salary_grade" 
                                   min="0" max="33" required placeholder="0-33">
                        </div>
                        
                        <?php if ($hasStepColumn): ?>
                        <div class="mb-3">
                            <label for="step_input" class="form-label">Step <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="step_input" name="step" 
                                   min="1" max="8" required placeholder="1-8">
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="monthly_salary" class="form-label">Monthly Salary <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="monthly_salary" name="monthly_salary" 
                                   step="0.01" min="0" required placeholder="e.g. 50000">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Annual Salary (Calculated)</label>
                            <input type="text" class="form-control" id="annual_salary_display" readonly placeholder="Auto-calculated from monthly">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save me-1"></i>Save Position
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-modal="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this position?</p>
                    <div class="alert alert-warning">
                        <strong>Position:</strong> <span id="deletePositionName"></span>
                    </div>
                    <p class="text-danger mb-0">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Batch Import Modal -->
    <div class="modal fade" id="batchImportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-csv me-2"></i>Batch Import Positions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Upload a CSV file with columns: <strong>position_title</strong>, <strong>salary_grade</strong>, <strong>step</strong>, <strong>monthly_salary</strong>. Salary grade 0–33; step 1–8; monthly salary must be greater than 0.</p>
                    <div class="mb-3">
                        <a href="position_api.php?action=download_template" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-download me-1"></i>Download CSV Template
                        </a>
                    </div>
                    <form id="batchImportForm">
                        <div class="mb-3">
                            <label for="csv_file" class="form-label">Select CSV file</label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                        </div>
                        <div id="batchImportResults" class="d-none"></div>
                        <div class="modal-footer px-0 pb-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="batchImportSubmitBtn">
                                <i class="fas fa-upload me-1"></i>Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script>
        let positionModalInstance;
        let deleteModalInstance;
        let batchImportModalInstance;
        let positionToDelete = null;
        let filterTimeout;
        let currentPage = <?php echo $page; ?>;

        // AJAX Filtering Functions
        function loadPositions(page = 1, search = '', salaryGrade = '') {
            currentPage = page;
            const container = document.getElementById('positions-container');
            const header = document.getElementById('position-list-header');
            
            if (!container) {
                console.error('Positions container not found');
                return;
            }
            
            // Show loading state
            container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Loading positions...</p></div>';
            
            // Build query string
            const params = new URLSearchParams();
            params.append('action', 'fetch');
            params.append('page', page);
            if (search) params.append('search', search);
            if (salaryGrade) params.append('salary_grade', salaryGrade);
            
            fetch('position_api.php?' + params.toString())
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            updatePositionsTable(data, data.hasStepColumn);
                            updatePagination(data.pagination, data.filters);
                            updateHeader(data.pagination);
                            updateURL(data.filters, page);
                        } else {
                            console.error('API Error:', data);
                            container.innerHTML = '<div class="alert alert-danger">Error loading positions: ' + (data.message || 'Unknown error') + '</div>';
                        }
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response text:', text);
                        container.innerHTML = '<div class="alert alert-danger">Error parsing server response. Please check console for details.</div>';
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    container.innerHTML = '<div class="alert alert-danger">An error occurred while loading positions: ' + error.message + '</div>';
                });
        }
        
        function updatePositionsTable(data, hasStepCol) {
            const container = document.getElementById('positions-container');
            const positions = data.positions;
            const showStep = hasStepCol !== false;
            
            if (positions.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-briefcase fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Positions Found</h5>
                        <p class="text-muted">No positions match your current filters.</p>
                    </div>
                    <div id="pagination-container"></div>
                `;
                return;
            }
            
            let tbodyHTML = '';
            positions.forEach(position => {
                const annualSalary = (parseFloat(position.annual_salary) * 12).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                const monthlySalary = parseFloat(position.annual_salary).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                const positionTitle = position.position_title.replace(/'/g, "\\'");
                const step = position.step ?? 1;
                const stepCell = showStep ? `<td>${step}</td>` : '';
                
                tbodyHTML += `
                    <tr>
                        <td>${position.id}</td>
                        <td><strong>${escapeHtml(position.position_title)}</strong></td>
                        <td><span class="badge bg-info">SG-${position.salary_grade}</span></td>
                        ${stepCell}
                        <td>₱${annualSalary}</td>
                        <td>₱${monthlySalary}</td>
                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-info" 
                                        onclick='editPosition(${JSON.stringify(position)})' 
                                        title="Edit position">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-danger" 
                                        onclick="deletePosition(${position.id}, '${positionTitle}')" 
                                        title="Delete position">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            container.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Position Title</th>
                                <th>Salary Grade</th>
                                ${showStep ? '<th>Step</th>' : ''}
                                <th>Annual Salary</th>
                                <th>Monthly Salary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="positions-tbody">
                            ${tbodyHTML}
                        </tbody>
                    </table>
                </div>
                <div id="pagination-container"></div>
            `;
        }
        
        function updatePagination(pagination, filters) {
            const container = document.getElementById('pagination-container');
            if (!container) {
                console.warn('Pagination container not found');
                return;
            }
            
            const { page, totalPages } = pagination;
            
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }
            
            const startPage = Math.max(1, page - 2);
            const endPage = Math.min(totalPages, page + 2);
            
            let paginationHTML = '<nav aria-label="Page navigation" class="mt-4"><ul class="pagination justify-content-center">';
            
            // Previous button
            paginationHTML += `
                <li class="page-item ${page <= 1 ? 'disabled' : ''}">
                    <a class="page-link pagination-link" href="#" data-page="${page - 1}" aria-label="Previous">
                        <span aria-hidden="true">&laquo; Previous</span>
                    </a>
                </li>
            `;
            
            // First page
            if (startPage > 1) {
                paginationHTML += `<li class="page-item"><a class="page-link pagination-link" href="#" data-page="1">1</a></li>`;
                if (startPage > 2) {
                    paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
            }
            
            // Page numbers
            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                    <li class="page-item ${i === page ? 'active' : ''}">
                        <a class="page-link pagination-link" href="#" data-page="${i}">${i}</a>
                    </li>
                `;
            }
            
            // Last page
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
                paginationHTML += `<li class="page-item"><a class="page-link pagination-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
            }
            
            // Next button
            paginationHTML += `
                <li class="page-item ${page >= totalPages ? 'disabled' : ''}">
                    <a class="page-link pagination-link" href="#" data-page="${page + 1}" aria-label="Next">
                        <span aria-hidden="true">Next &raquo;</span>
                    </a>
                </li>
            `;
            
            paginationHTML += '</ul></nav>';
            container.innerHTML = paginationHTML;
            
            // Attach click handlers to pagination links
            document.querySelectorAll('.pagination-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (!this.closest('.page-item').classList.contains('disabled')) {
                        const pageNum = parseInt(this.getAttribute('data-page'));
                        const search = document.getElementById('search').value.trim();
                        const salaryGrade = document.getElementById('salary_grade').value;
                        loadPositions(pageNum, search, salaryGrade);
                    }
                });
            });
        }
        
        function updateHeader(pagination) {
            const header = document.getElementById('position-list-header');
            const { totalRecords, offset, perPage } = pagination;
            
            if (totalRecords > 0) {
                const start = offset + 1;
                const end = Math.min(offset + perPage, totalRecords);
                header.innerHTML = `<i class="fas fa-list me-2"></i>Position List (Showing ${start}-${end} of ${totalRecords})`;
            } else {
                header.innerHTML = `<i class="fas fa-list me-2"></i>Position List (0)`;
            }
        }
        
        function updateURL(filters, page) {
            const params = new URLSearchParams();
            if (page > 1) params.append('page', page);
            if (filters.search) params.append('search', filters.search);
            if (filters.salary_grade) params.append('salary_grade', filters.salary_grade);
            
            const newURL = 'positions.php' + (params.toString() ? '?' + params.toString() : '');
            window.history.pushState({}, '', newURL);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            positionModalInstance = new bootstrap.Modal(document.getElementById('positionModal'));
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteModal'));
            if (document.getElementById('batchImportModal')) {
                batchImportModalInstance = new bootstrap.Modal(document.getElementById('batchImportModal'));
            }
            
            // AJAX Filtering - Salary grade filter (auto-filter on change)
            const salaryGradeSelect = document.getElementById('salary_grade');
            if (salaryGradeSelect) {
                salaryGradeSelect.addEventListener('change', function() {
                    const search = document.getElementById('search').value.trim();
                    loadPositions(1, search, this.value);
                });
            }
            
            // AJAX Filtering - Search input (debounced auto-filter)
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(filterTimeout);
                    filterTimeout = setTimeout(() => {
                        const salaryGrade = document.getElementById('salary_grade').value;
                        loadPositions(1, this.value.trim(), salaryGrade);
                    }, 500); // Wait 500ms after user stops typing
                });
            }
            
            // AJAX Filtering - Search button
            const searchBtn = document.getElementById('searchBtn');
            if (searchBtn) {
                searchBtn.addEventListener('click', function() {
                    const search = document.getElementById('search').value.trim();
                    const salaryGrade = document.getElementById('salary_grade').value;
                    loadPositions(1, search, salaryGrade);
                });
            }
            
            // AJAX Filtering - Clear button
            const clearBtn = document.getElementById('clearBtn');
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    document.getElementById('search').value = '';
                    document.getElementById('salary_grade').value = '';
                    loadPositions(1, '', '');
                });
            }
            
            // Calculate annual salary when monthly salary changes
            document.getElementById('monthly_salary').addEventListener('input', function() {
                const monthlySalary = parseFloat(this.value) || 0;
                const annualSalary = monthlySalary * 12;
                document.getElementById('annual_salary_display').value = '₱' + annualSalary.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            });
            
            // Handle form submission
            document.getElementById('positionForm').addEventListener('submit', function(e) {
                e.preventDefault();
                savePosition();
            });
            
            // Handle delete confirmation
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                if (positionToDelete) {
                    performDelete(positionToDelete);
                }
            });

            // Batch import form
            const batchImportForm = document.getElementById('batchImportForm');
            if (batchImportForm) {
                batchImportForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitBatchImport();
                });
            }
        });

        function showBatchImportModal() {
            document.getElementById('batchImportForm').reset();
            document.getElementById('batchImportResults').classList.add('d-none');
            document.getElementById('batchImportResults').innerHTML = '';
            batchImportModalInstance.show();
        }

        function submitBatchImport() {
            const fileInput = document.getElementById('csv_file');
            if (!fileInput.files.length) {
                showError('Please select a CSV file.');
                return;
            }
            const formData = new FormData();
            formData.append('action', 'batch_import');
            formData.append('csv_file', fileInput.files[0]);
            const btn = document.getElementById('batchImportSubmitBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Importing...';
            const resultsEl = document.getElementById('batchImportResults');
            resultsEl.classList.add('d-none');
            resultsEl.innerHTML = '';
            fetch('position_api.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        let html = '<div class="alert alert-success">' + (data.message || '') + '</div>';
                        if (data.errors && data.errors.length) {
                            html += '<div class="alert alert-warning"><strong>Row errors:</strong><ul class="mb-0 small">';
                            data.errors.slice(0, 20).forEach(function(err) { html += '<li>' + escapeHtml(err) + '</li>'; });
                            if (data.errors.length > 20) {
                                html += '<li>... and ' + (data.errors.length - 20) + ' more.</li>';
                            }
                            html += '</ul></div>';
                        }
                        resultsEl.innerHTML = html;
                        resultsEl.classList.remove('d-none');
                        if (data.created > 0) {
                            showSuccess(data.message);
                            setTimeout(function() { window.location.reload(); }, 1500);
                        }
                    } else {
                        showError(data.message || 'Import failed.');
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    showError('An error occurred during import.');
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Position';
            document.getElementById('positionForm').reset();
            document.getElementById('action').value = 'create';
            document.getElementById('position_id').value = '';
            document.getElementById('annual_salary_display').value = '';
            const stepInput = document.getElementById('step_input');
            if (stepInput) stepInput.value = '1';
            positionModalInstance.show();
        }

        function editPosition(position) {
            document.getElementById('modalTitle').textContent = 'Edit Position';
            document.getElementById('action').value = 'update';
            document.getElementById('position_id').value = position.id;
            document.getElementById('position_title').value = position.position_title;
            document.getElementById('salary_grade_input').value = position.salary_grade;
            const stepInput = document.getElementById('step_input');
            if (stepInput) stepInput.value = position.step ?? 1;
            // DB stores monthly salary
            const monthlySalary = Number(position.annual_salary) || 0;
            document.getElementById('monthly_salary').value = monthlySalary;
            document.getElementById('annual_salary_display').value = '₱' + (monthlySalary * 12).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            
            positionModalInstance.show();
        }

        const hasStepColumn = <?php echo $hasStepColumn ? 'true' : 'false'; ?>;
        
        function savePosition() {
            const formData = new FormData(document.getElementById('positionForm'));
            // Convert monthly to annual for API (API expects annual and divides by 12)
            const monthlySalary = parseFloat(document.getElementById('monthly_salary').value) || 0;
            formData.set('annual_salary', (monthlySalary * 12).toString());
            if (!hasStepColumn) formData.delete('step');
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            
            fetch('position_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    positionModalInstance.hide();
                    showSuccess(data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showError(data.message);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('An error occurred while saving the position.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }

        function deletePosition(id, name) {
            positionToDelete = id;
            document.getElementById('deletePositionName').textContent = name;
            deleteModalInstance.show();
        }

        function performDelete(positionId) {
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            const originalText = confirmBtn.innerHTML;
            
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('position_id', positionId);
            
            fetch('position_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    deleteModalInstance.hide();
                    showSuccess(data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showError(data.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('An error occurred while deleting the position.');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = originalText;
            });
        }

        function exportPositions() {
            window.location.href = 'position_api.php?action=export';
        }

        function showSuccess(message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.main-content').insertBefore(
                alertDiv, 
                document.querySelector('.main-content').firstChild.nextSibling
            );
        }

        function showError(message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.main-content').insertBefore(
                alertDiv, 
                document.querySelector('.main-content').firstChild.nextSibling
            );
        }

        function loadPositions(page = 1, search = '', salaryGrade = '') {
            currentPage = page;
            const container = document.getElementById('positions-container');
            const header = document.getElementById('position-list-header');
            
            if (!container) {
                console.error('Positions container not found');
                return;
            }
            
            // Show loading state
            container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Loading positions...</p></div>';
            
            // Build query string
            const params = new URLSearchParams();
            params.append('action', 'fetch');
            params.append('page', page);
            if (search) params.append('search', search);
            if (salaryGrade) params.append('salary_grade', salaryGrade);
            
            fetch('position_api.php?' + params.toString())
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            updatePositionsTable(data, data.hasStepColumn);
                            updatePagination(data.pagination, data.filters);
                            updateHeader(data.pagination);
                            updateURL(data.filters, page);
                        } else {
                            console.error('API Error:', data);
                            container.innerHTML = '<div class="alert alert-danger">Error loading positions: ' + (data.message || 'Unknown error') + '</div>';
                        }
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response text:', text);
                        container.innerHTML = '<div class="alert alert-danger">Error parsing server response. Please check console for details.</div>';
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    container.innerHTML = '<div class="alert alert-danger">An error occurred while loading positions: ' + error.message + '</div>';
                });
        }
        
        function updatePositionsTable(data, hasStepCol) {
            const container = document.getElementById('positions-container');
            const positions = data.positions;
            const showStep = hasStepCol !== false;
            
            if (positions.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-briefcase fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Positions Found</h5>
                        <p class="text-muted">No positions match your current filters.</p>
                    </div>
                    <div id="pagination-container"></div>
                `;
                return;
            }
            
            let tbodyHTML = '';
            positions.forEach(position => {
                const annualSalary = (parseFloat(position.annual_salary) * 12).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                const monthlySalary = parseFloat(position.annual_salary).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                const positionTitle = position.position_title.replace(/'/g, "\\'");
                const step = position.step ?? 1;
                const stepCell = showStep ? `<td>${step}</td>` : '';
                
                tbodyHTML += `
                    <tr>
                        <td>${position.id}</td>
                        <td><strong>${escapeHtml(position.position_title)}</strong></td>
                        <td><span class="badge bg-info">SG-${position.salary_grade}</span></td>
                        ${stepCell}
                        <td>₱${annualSalary}</td>
                        <td>₱${monthlySalary}</td>
                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-info" 
                                        onclick='editPosition(${JSON.stringify(position)})' 
                                        title="Edit position">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-danger" 
                                        onclick="deletePosition(${position.id}, '${positionTitle}')" 
                                        title="Delete position">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            container.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Position Title</th>
                                <th>Salary Grade</th>
                                ${showStep ? '<th>Step</th>' : ''}
                                <th>Annual Salary</th>
                                <th>Monthly Salary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="positions-tbody">
                            ${tbodyHTML}
                        </tbody>
                    </table>
                </div>
                <div id="pagination-container"></div>
            `;
        }
        
        function updatePagination(pagination, filters) {
            const container = document.getElementById('pagination-container');
            if (!container) {
                console.warn('Pagination container not found');
                return;
            }
            
            const { page, totalPages } = pagination;
            
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }
            
            const startPage = Math.max(1, page - 2);
            const endPage = Math.min(totalPages, page + 2);
            
            let paginationHTML = '<nav aria-label="Page navigation" class="mt-4"><ul class="pagination justify-content-center">';
            
            // Previous button
            paginationHTML += `
                <li class="page-item ${page <= 1 ? 'disabled' : ''}">
                    <a class="page-link pagination-link" href="#" data-page="${page - 1}" aria-label="Previous">
                        <span aria-hidden="true">&laquo; Previous</span>
                    </a>
                </li>
            `;
            
            // First page
            if (startPage > 1) {
                paginationHTML += `<li class="page-item"><a class="page-link pagination-link" href="#" data-page="1">1</a></li>`;
                if (startPage > 2) {
                    paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
            }
            
            // Page numbers
            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                    <li class="page-item ${i === page ? 'active' : ''}">
                        <a class="page-link pagination-link" href="#" data-page="${i}">${i}</a>
                    </li>
                `;
            }
            
            // Last page
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
                paginationHTML += `<li class="page-item"><a class="page-link pagination-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
            }
            
            // Next button
            paginationHTML += `
                <li class="page-item ${page >= totalPages ? 'disabled' : ''}">
                    <a class="page-link pagination-link" href="#" data-page="${page + 1}" aria-label="Next">
                        <span aria-hidden="true">Next &raquo;</span>
                    </a>
                </li>
            `;
            
            paginationHTML += '</ul></nav>';
            container.innerHTML = paginationHTML;
            
            // Attach click handlers to pagination links
            document.querySelectorAll('.pagination-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (!this.closest('.page-item').classList.contains('disabled')) {
                        const pageNum = parseInt(this.getAttribute('data-page'));
                        const search = document.getElementById('search').value.trim();
                        const salaryGrade = document.getElementById('salary_grade').value;
                        loadPositions(pageNum, search, salaryGrade);
                    }
                });
            });
        }
        
        function updateHeader(pagination) {
            const header = document.getElementById('position-list-header');
            const { totalRecords, offset, perPage } = pagination;
            
            if (totalRecords > 0) {
                const start = offset + 1;
                const end = Math.min(offset + perPage, totalRecords);
                header.innerHTML = `<i class="fas fa-list me-2"></i>Position List (Showing ${start}-${end} of ${totalRecords})`;
            } else {
                header.innerHTML = `<i class="fas fa-list me-2"></i>Position List (0)`;
            }
        }
        
        function updateURL(filters, page) {
            const params = new URLSearchParams();
            if (page > 1) params.append('page', page);
            if (filters.search) params.append('search', filters.search);
            if (filters.salary_grade) params.append('salary_grade', filters.salary_grade);
            
            const newURL = 'positions.php' + (params.toString() ? '?' + params.toString() : '');
            window.history.pushState({}, '', newURL);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize filtering
        document.addEventListener('DOMContentLoaded', function() {
            // Salary grade filter - auto-filter on change
            const salaryGradeSelect = document.getElementById('salary_grade');
            if (salaryGradeSelect) {
                salaryGradeSelect.addEventListener('change', function() {
                    const search = document.getElementById('search').value.trim();
                    loadPositions(1, search, this.value);
                });
            }
            
            // Search input - debounced auto-filter
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(filterTimeout);
                    filterTimeout = setTimeout(() => {
                        const salaryGrade = document.getElementById('salary_grade').value;
                        loadPositions(1, this.value.trim(), salaryGrade);
                    }, 500); // Wait 500ms after user stops typing
                });
            }
            
            // Search button
            const searchBtn = document.getElementById('searchBtn');
            if (searchBtn) {
                searchBtn.addEventListener('click', function() {
                    const search = document.getElementById('search').value.trim();
                    const salaryGrade = document.getElementById('salary_grade').value;
                    loadPositions(1, search, salaryGrade);
                });
            }
            
            // Clear button
            const clearBtn = document.getElementById('clearBtn');
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    document.getElementById('search').value = '';
                    document.getElementById('salary_grade').value = '';
                    loadPositions(1, '', '');
                });
            }
        });
    </script>
</body>
</html>

