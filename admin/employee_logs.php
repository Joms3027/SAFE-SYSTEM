<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Filter/search AJAX (GET ?ajax=1): never redirect — fetch() follows 302 and the response body is
// login HTML, which breaks response.json() with "Unexpected token '<'".
$employeeLogsAjaxGet = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && isset($_GET['ajax'])
    && (string) $_GET['ajax'] === '1';

if ($employeeLogsAjaxGet) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized - Please refresh the page.',
            'resultsHtml' => '',
            'cardHeaderHtml' => '',
        ]);
        exit;
    }
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === '') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Session incomplete. Please log in again.',
            'resultsHtml' => '',
            'cardHeaderHtml' => '',
        ]);
        exit;
    }
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin privileges required.',
            'resultsHtml' => '',
            'cardHeaderHtml' => '',
        ]);
        exit;
    }
}

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Handle add / edit employee deduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee_deduction'])) {
    $employee_id = $_POST['employee_id'] ?? '';
    $deduction_id = $_POST['deduction_id'] ?? 0;
    $amount = floatval($_POST['amount'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $remarks = $_POST['remarks'] ?? '';
    $ed_id = isset($_POST['ed_id']) ? intval($_POST['ed_id']) : 0;
    $is_tardiness = isset($_POST['is_tardiness']) && $_POST['is_tardiness'] == '1';
    
    try {
        // Helper function to find or create deduction item
        $findOrCreateDeduction = function($itemName, $objectCode, $accountTitle) use ($db) {
            $stmt = $db->prepare("SELECT id FROM deductions WHERE item_name = ? AND type = 'Deduct' LIMIT 1");
            $stmt->execute([$itemName]);
            $deduction = $stmt->fetch();
            
            if ($deduction) {
                return intval($deduction['id']);
            } else {
                // Create deduction if it doesn't exist
                $maxOrderStmt = $db->query("SELECT MAX(order_num) as max_order FROM deductions");
                $maxOrder = $maxOrderStmt->fetch();
                $nextOrder = ($maxOrder['max_order'] ?? 0) + 1;
                
                $createStmt = $db->prepare("INSERT INTO deductions (item_name, type, object_code, dr_cr, account_title, order_num, is_active) VALUES (?, 'Deduct', ?, 'Dr', ?, ?, 1)");
                $createStmt->execute([$itemName, $objectCode, $accountTitle, $nextOrder]);
                return intval($db->lastInsertId());
            }
        };
        
        // Handle tardiness special case
        if ($is_tardiness || $deduction_id === 'tardiness') {
            $deduction_id = $findOrCreateDeduction('Tardiness', '5010101999', 'Tardiness Deduction');
            // Add tardiness time to remarks if provided
            if (isset($_POST['tardiness_time'])) {
                $timeInfo = "Time of tardiness: " . $_POST['tardiness_time'] . " (multiplied by 2)";
                $remarks = !empty($remarks) ? $timeInfo . ". " . $remarks : $timeInfo;
            } elseif (isset($_POST['tardiness_hours'])) {
                $hoursInfo = "Hours of tardiness: " . floatval($_POST['tardiness_hours']) . " (multiplied by 2)";
                $remarks = !empty($remarks) ? $hoursInfo . ". " . $remarks : $hoursInfo;
            }
        } else {
            $deduction_id = intval($deduction_id);
        }
        
        if ($deduction_id <= 0) {
            throw new Exception("Invalid deduction selected");
        }
        if ($ed_id > 0) {
            // Update existing
            $stmt = $db->prepare("UPDATE employee_deductions SET amount = ?, start_date = ?, end_date = ?, remarks = ?, is_active = 1 WHERE id = ?");
            $stmt->execute([$amount, $start_date, $end_date, $remarks, $ed_id]);
            $message = "Deduction updated successfully!";
        } else {
            // Check if exists
            $checkStmt = $db->prepare("SELECT id FROM employee_deductions WHERE employee_id = ? AND deduction_id = ?");
            $checkStmt->execute([$employee_id, $deduction_id]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                $stmt = $db->prepare("UPDATE employee_deductions SET amount = ?, start_date = ?, end_date = ?, remarks = ?, is_active = 1 WHERE id = ?");
                $stmt->execute([$amount, $start_date, $end_date, $remarks, $existing['id']]);
                $message = "Deduction updated successfully!";
            } else {
                $stmt = $db->prepare("INSERT INTO employee_deductions (employee_id, deduction_id, amount, start_date, end_date, remarks, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$employee_id, $deduction_id, $amount, $start_date, $end_date, $remarks]);
                $message = "Deduction added successfully!";
            }
        }
        
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
        }
        
        $_SESSION['success'] = $message;
    } catch (Exception $e) {
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error'] = $e->getMessage();
    }
}

// Handle delete employee deduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee_deduction'])) {
    $deduction_id = intval($_POST['delete_employee_deduction']);
    $employee_id = $_POST['employee_id'] ?? '';
    
    try {
        $stmt = $db->prepare("DELETE FROM employee_deductions WHERE id = ?");
        $stmt->execute([$deduction_id]);
        
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Deduction removed successfully!']);
            exit;
        }
        
        $_SESSION['success'] = 'Deduction removed successfully!';
    } catch (Exception $e) {
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error'] = $e->getMessage();
    }
}

// Get search filter
$search = $_GET['search'] ?? '';
$positionFilter = $_GET['position'] ?? '';
$departmentFilter = $_GET['department'] ?? '';
$employmentStatusFilter = $_GET['employment_status'] ?? '';

// Build query to get employees with their names, positions, and rates
$whereClause = "u.user_type IN ('faculty','staff') AND u.is_active = 1";
$params = [];

if ($search) {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR fp.employee_id LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if ($positionFilter) {
    $whereClause .= " AND fp.position = ?";
    $params[] = $positionFilter;
}

if ($departmentFilter) {
    $whereClause .= " AND fp.department = ?";
    $params[] = $departmentFilter;
}

if ($employmentStatusFilter) {
    $whereClause .= " AND fp.employment_status = ?";
    $params[] = $employmentStatusFilter;
}

// Pagination: 10 employees per page
$perPage = 10;
$countSql = "SELECT COUNT(DISTINCT u.id) 
             FROM users u
             LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
             WHERE $whereClause";
$pagination = getPaginationParams($db, $countSql, $params, $perPage);

// Build main query with pagination
$sql = "SELECT 
            u.id,
            u.first_name,
            u.last_name,
            fp.employee_id,
            fp.position,
            ps.annual_salary,
            ps.salary_grade,
            fp.department,
            fp.employment_type,
            fp.employment_status
        FROM users u
        LEFT JOIN (
            SELECT fp1.user_id, fp1.employee_id, fp1.position, fp1.department, fp1.employment_type, fp1.employment_status
            FROM faculty_profiles fp1
            INNER JOIN (
                SELECT user_id, MAX(id) as max_id
                FROM faculty_profiles
                GROUP BY user_id
            ) fp2 ON fp1.user_id = fp2.user_id AND fp1.id = fp2.max_id
        ) fp ON u.id = fp.user_id
        LEFT JOIN (
            SELECT ps1.position_title, ps1.salary_grade, ps1.annual_salary
            FROM position_salary ps1
            INNER JOIN (
                SELECT position_title, MIN(id) as min_id
                FROM position_salary
                GROUP BY position_title
            ) ps2 ON ps1.position_title = ps2.position_title AND ps1.id = ps2.min_id
        ) ps ON fp.position = ps.position_title
        WHERE $whereClause
        GROUP BY u.id, u.first_name, u.last_name, fp.employee_id, fp.position, ps.annual_salary, ps.salary_grade, fp.department, fp.employment_type, fp.employment_status
        ORDER BY u.last_name ASC, u.first_name ASC
        LIMIT {$pagination['limit']} OFFSET {$pagination['offset']}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Get unique positions for filter dropdown
$positionsStmt = $db->query("SELECT DISTINCT position FROM faculty_profiles WHERE position IS NOT NULL AND position != '' ORDER BY position ASC");
$positions = $positionsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get unique departments for filter dropdown
$departmentsStmt = $db->query("SELECT DISTINCT department FROM faculty_profiles WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departments = $departmentsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get employment statuses for filter dropdown (from master table)
$employmentStatusesStmt = $db->query("SELECT name FROM employment_statuses ORDER BY name");
$employmentStatuses = $employmentStatusesStmt->fetchAll(PDO::FETCH_COLUMN);

// AJAX response for filter/search (no page reload)
if (!empty($_GET['ajax']) && $_GET['ajax'] === '1') {
    $buildResultsHtml = function() use ($employees, $pagination, $db, $search, $positionFilter, $departmentFilter, $employmentStatusFilter) {
        ob_start();
        if (empty($employees)): ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <span class="empty-title">No Employees Found</span>
                <p class="mb-0">No employees match your current filters. Try adjusting your search criteria.</p>
                <?php if ($search || $positionFilter || $departmentFilter || $employmentStatusFilter): ?>
                    <a href="employee_logs.php" class="btn btn-outline-primary mt-3">
                        <i class="fas fa-redo me-1"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive-scroll">
                <table class="table align-middle" id="employeesTable">
                    <thead>
                        <tr>
                            <th>#</th><th>Safe Employee ID</th><th>Name</th><th>Position</th><th>Department</th><th>Salary Grade</th><th>Monthly Rate</th><th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="employeesTableBody">
                <?php
                $counter = 1;
                foreach ($employees as $employee):
                    $fullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
                    $annualSalary = floatval($employee['annual_salary'] ?? 0);
                    $position = $employee['position'] ?? '';
                    $empId = $employee['employee_id'] ?? '';
                    $employmentType = $employee['employment_type'] ?? '';
                    $monthlyRate = 0;
                    if ($annualSalary > 0) {
                        if ($annualSalary < 200000) $annualSalary = $annualSalary * 12;
                        $monthlyRate = $annualSalary / 12;
                    } elseif ($position) {
                        $fallbackStmt = $db->prepare("SELECT monthly_rate FROM positions WHERE position_name = ? LIMIT 1");
                        $fallbackStmt->execute([$position]);
                        $posRow = $fallbackStmt->fetch();
                        if ($posRow && !empty($posRow['monthly_rate'])) $monthlyRate = floatval($posRow['monthly_rate']);
                        else {
                            if (stripos($position, 'Instructor 1') !== false) $monthlyRate = 19300;
                            elseif (stripos($position, 'Instructor 2') !== false) $monthlyRate = 21000;
                            elseif (stripos($position, 'Instructor 3') !== false) $monthlyRate = 23000;
                        }
                    } ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php if ($empId): ?><span class="badge bg-primary"><?php echo htmlspecialchars($empId); ?></span><?php else: ?><span class="text-muted small">Not assigned</span><?php endif; ?></td>
                            <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                            <td><?php echo $position ? '<strong>' . htmlspecialchars($position) . '</strong>' : '<span class="text-muted">Not specified</span>'; ?></td>
                            <td><?php echo htmlspecialchars($employee['department'] ?: 'Not specified'); ?></td>
                            <td><?php echo $employee['salary_grade'] ? '<span class="badge bg-info">SG-' . htmlspecialchars($employee['salary_grade']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo $monthlyRate > 0 ? '<strong class="text-primary">₱' . number_format($monthlyRate, 2) . '</strong>' : '<span class="text-muted">Not available</span>'; ?></td>
                            <td class="text-center"><?php if ($empId): ?>
                                <div class="action-buttons-container">
                                    <button onclick="viewLogs('<?php echo htmlspecialchars($empId); ?>', '<?php echo htmlspecialchars(addslashes($fullName)); ?>')" class="btn btn-primary btn-sm" title="View Attendance Logs"><i class="fas fa-clock"></i><span class="d-none d-md-inline">DTR</span></button>
                                    <button onclick="if(typeof window.manageDeductions === 'function') { window.manageDeductions('<?php echo htmlspecialchars($empId); ?>', '<?php echo htmlspecialchars(addslashes($fullName)); ?>', '<?php echo htmlspecialchars(addslashes($position)); ?>', '<?php echo htmlspecialchars(addslashes($employmentType)); ?>'); } else { alert('Function not loaded.'); }" class="btn btn-purple btn-sm" title="Manage Deductions"><i class="fas fa-list"></i><span class="d-none d-md-inline">Deductions</span></button>
                                    <button onclick="if(typeof window.viewSalary === 'function') { window.viewSalary('<?php echo htmlspecialchars($empId); ?>', '<?php echo htmlspecialchars(addslashes($fullName)); ?>', '<?php echo htmlspecialchars(addslashes($position)); ?>'); } else { alert('Function not loaded.'); }" class="btn btn-success btn-sm" title="View Salary Information"><i class="fas fa-money-bill"></i><span class="d-none d-md-inline">Salary</span></button>
                                </div>
                            <?php else: ?><span class="text-muted small"><i class="fas fa-info-circle me-1"></i>N/A</span><?php endif; ?></td>
                        </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pagination['totalPages'] > 1): ?>
            <div class="mt-3 employee-logs-pagination"><?php echo renderPagination($pagination['page'], $pagination['totalPages']); ?></div>
            <?php endif;
        endif;
        return ob_get_clean();
    };
    $resultsHtml = $buildResultsHtml();
    $cardHeaderHtml = '<span class="badge bg-primary">' . (int)$pagination['total'] . ' total</span>';
    if ($pagination['totalPages'] > 1) {
        $cardHeaderHtml .= ' <span class="badge bg-info">Page ' . (int)$pagination['page'] . ' of ' . (int)$pagination['totalPages'] . '</span>';
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'resultsHtml' => $resultsHtml, 'cardHeaderHtml' => $cardHeaderHtml]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Employee Logs', 'View employee names, positions, and rates');
    ?>
    <style>
        .modal-backdrop-custom {
            background-color: rgba(0, 0, 0, 0.6);
        }
        .salary-summary-box {
            padding: 1rem;
            border-radius: 0.25rem;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        .salary-summary-box.gross {
            background-color: #f8f9fa;
            border-color: #0d6efd;
        }
        .salary-summary-box.deductions {
            background-color: #f8f9fa;
            border-color: #dc3545;
        }
        .salary-summary-box.net {
            background-color: #f8f9fa;
            border-color: #198754;
        }
        
        /* Admin employee logs filters card - unique class, 100vh height */
        .card-admin-employee-logs-filters,
        .filter-card {
            height: 24vh !important;
            /* min-height: 100vh !important; */
        }

        /* Enhanced Filter Section */
        .filter-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.02);
            border: 1px solid rgba(226, 232, 240, 0.9);
            transition: box-shadow 0.2s ease;
        }
        
        
        .filter-card .card-body {
            padding: 1.5rem;
        }
        
        .filter-card .form-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .filter-card .form-control {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 0.625rem 0.875rem;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        
        .filter-card .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            outline: none;
        }
        
        .filter-card .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.625rem 1rem;
            transition: all 0.2s ease;
        }
        
        .filter-card .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        /* Search Loading Indicator */
        .search-loading {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }
        
        .search-loading.active {
            display: block;
        }
        
        .search-loading .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 2px;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        /* Modern Table Styling */
        #employeesTable {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            background: #fff;
        }
        
        #employeesTable thead th {
            background: linear-gradient(180deg, #f8f9fa 0%, #f1f3f5 100%);
            color: #495057;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1.25rem 0.75rem;
            border-bottom: 2px solid #e9ecef;
            border-top: none;
            white-space: nowrap;
        }
        
        #employeesTable tbody td {
            padding: 1.125rem 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            font-size: 0.9rem;
            transition: background-color 0.15s ease;
        }
        
        #employeesTable tbody tr {
            transition: all 0.2s ease;
            background-color: #fff;
        }
        
        #employeesTable tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }
        
        #employeesTable tbody tr:hover {
            background-color: #f0f4f8 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        /* Holiday rows in attendance log / DTR modal */
        #logsTableBody tr.dtr-row-holiday {
            background-color: #ffdddd !important;
        }
        #logsTableBody tr.dtr-row-holiday:hover {
            background-color: #ffcccc !important;
        }
        #logsTableBody tr.dtr-row-half-day-holiday {
            background-color: #fff3e0 !important;
        }
        #logsTableBody tr.dtr-row-half-day-holiday:hover {
            background-color: #ffe0b2 !important;
        }
        /* Red highlight when employee came in on a holiday */
        #logsTableBody tr.dtr-row-holiday-attendance {
            background-color: #f8d7da !important;
        }
        #logsTableBody tr.dtr-row-holiday-attendance:hover {
            background-color: #f5c6cb !important;
        }
        
        #employeesTable tbody tr:last-child td {
            border-bottom: none;
        }
        
        #employeesTable .badge {
            font-weight: 500;
            padding: 0.4em 0.75em;
            font-size: 0.8em;
            border-radius: 6px;
            letter-spacing: 0.3px;
        }
        
        #employeesTable .btn-group-sm {
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        #employeesTable .btn-group-sm .btn {
            padding: 0.5rem 0.875rem;
            font-size: 0.85rem;
            border-radius: 8px;
            transition: all 0.2s ease;
            font-weight: 500;
            border: none;
            margin: 0;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        #employeesTable .btn-group-sm .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        #employeesTable .btn-group-sm .btn:active {
            transform: translateY(0);
        }
        
        #employeesTable .btn-group-sm .btn i {
            font-size: 0.875rem;
        }
        
        #employeesTable .btn-primary {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: #fff;
        }
        
        #employeesTable .btn-info {
            background: linear-gradient(135deg, #0dcaf0 0%, #0aa2c0 100%);
            color: #fff;
        }
        
        #employeesTable .btn-success {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: #fff;
        }
        
        #employeesTable .btn-purple {
            background: linear-gradient(135deg, #9333ea 0%, #7e22ce 100%);
            color: #fff;
        }
        
        #employeesTable tbody td strong {
            color: #212529;
            font-weight: 600;
        }
        
        #employeesTable tbody td .text-primary {
            color: #0d6efd !important;
            font-weight: 600;
        }
        
        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1rem;
            display: block;
        }
        
        .empty-state .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .empty-state p {
            color: #6b7280;
            font-size: 0.95rem;
        }
        
        /* Scrollable Table Container */
        .table-responsive-scroll {
            max-height: 650px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .table-responsive-scroll::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        .table-responsive-scroll::-webkit-scrollbar-track {
            background: #f1f3f5;
            border-radius: 5px;
        }
        
        .table-responsive-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 5px;
            transition: background 0.2s ease;
        }
        
        
        #employeesTable thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(180deg, #f8f9fa 0%, #f1f3f5 100%);
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.1);
        }
        
        /* Enhanced Card Styling */
        .card {
            border-radius: 12px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.02);
            transition: box-shadow 0.2s ease;
        }
        
        
        .card-header {
            background: linear-gradient(180deg, #f8f9fa 0%, #f1f3f5 100%);
            border-bottom: 1px solid #e9ecef;
            padding: 1.25rem 1.5rem;
            border-radius: 12px 12px 0 0;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
            color: #212529;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-header h5 i {
            color: #0d6efd;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Enhanced Pagination */
        .pagination {
            gap: 0.5rem;
        }
        
        .pagination .page-link {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 0.5rem 0.875rem;
            color: #495057;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            border-color: #0d6efd;
            color: #fff;
        }
        
        /* Responsive Improvements */
        @media (max-width: 768px) {
            .filter-card .card-body {
                padding: 1rem;
            }
            
            .filter-card .row > div {
                margin-bottom: 1rem;
            }
            
            .filter-card .row > div:last-child {
                margin-bottom: 0;
            }
            
            #employeesTable thead th,
            #employeesTable tbody td {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
            }
            
            #employeesTable thead th {
                font-size: 0.75rem;
                padding: 0.875rem 0.5rem;
            }
            
            #employeesTable .btn-group-sm .btn,
            .action-buttons-container .btn {
                padding: 0.35rem 0.5rem;
                font-size: 0.75rem;
                flex: 0 1 auto;
                min-width: auto;
            }
            
            .action-buttons-container {
                flex-wrap: nowrap;
                gap: 0.25rem;
            }
            
            .action-buttons-container .btn {
                width: auto;
                justify-content: center;
            }
            
            .action-buttons-container .btn .d-none {
                display: none !important;
            }
            
            .table-responsive-scroll {
                max-height: 500px;
            }
            
            .card-header h5 {
                font-size: 1rem;
            }
            
            .card-header .badge {
                font-size: 0.75rem;
                padding: 0.3em 0.6em;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 1rem !important;
            }
            
            .filter-card .card-body {
                padding: 0.875rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .card-header {
                padding: 1rem;
            }
        }
        
        /* Loading State */
        .table-loading {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        
        .table-loading .spinner-border {
            width: 3rem;
            height: 3rem;
            border-width: 3px;
            color: #0d6efd;
        }
        
        /* Badge Improvements */
        .badge {
            font-weight: 500;
            padding: 0.4em 0.75em;
            border-radius: 6px;
        }
        
        /* Action Buttons Container */
        .action-buttons-container {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.35rem;
            justify-content: center;
            align-items: center;
        }
        
        .action-buttons-container .btn {
            padding: 0.4rem 0.65rem;
            font-size: 0.8rem;
            white-space: nowrap;
            flex: 0 1 auto;
        }
    </style>
</head>
<body class="layout-admin">
    <script>
        // Suppress 403/401 errors from notifications and chat APIs in console (run early, before other scripts)
        (function() {
            const originalError = console.error;
            const originalWarn = console.warn;
            
            console.error = function(...args) {
                const message = args.join(' ').toLowerCase();
                // Suppress 403/401 errors from API calls
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized') ||
                    (message.includes('fetch failed') && (message.includes('notifications_api') || message.includes('chat_api'))) ||
                    (message.includes('error loading conversations') && (message.includes('403') || message.includes('forbidden'))) ||
                    (message.includes('error checking notifications') && (message.includes('403') || message.includes('forbidden'))) ||
                    (message.includes('network response was not ok') && (message.includes('403') || message.includes('forbidden')))) {
                    return; // Don't log these errors
                }
                originalError.apply(console, args);
            };
            
            // Also suppress warnings for 403 errors
            console.warn = function(...args) {
                const message = args.join(' ').toLowerCase();
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized')) {
                    return; // Don't log these warnings
                }
                originalWarn.apply(console, args);
            };
        })();
    </script>
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Employee Logs',
                    '',
                    'fas fa-clipboard-list',
                    [],
                    '<button type="button" class="btn btn-success me-2" onclick="openBulkPrintDTRModal()"><i class="fas fa-print me-1"></i>Print All DTR</button><button type="button" class="btn btn-danger me-2" onclick="openAllTardinessModal()"><i class="fas fa-clock me-1"></i>All Tardiness</button><button type="button" class="btn btn-warning" onclick="openBatchCalculationModal()"><i class="fas fa-calculator me-1"></i>Batch Calculation</button>'
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Filters -->
                <div class="card card-admin-employee-logs-filters filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3" id="filterForm">
                            <div class="col-md-3">
                                <label for="search" class="form-label">
                                    <i class="fas fa-search me-1 text-muted"></i>Search
                                </label>
                                <div class="search-input-wrapper">
                                    <input type="text" class="form-control" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Search by name or Safe Employee ID">
                                    <div class="search-loading" id="searchLoading">
                                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="position" class="form-label">
                                    <i class="fas fa-briefcase me-1 text-muted"></i>Position
                                </label>
                                <select class="form-control" id="position" name="position">
                                    <option value="">All Positions</option>
                                    <?php foreach ($positions as $pos): ?>
                                        <option value="<?php echo htmlspecialchars($pos); ?>" 
                                                <?php echo $positionFilter === $pos ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pos); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="department" class="form-label">
                                    <i class="fas fa-building me-1 text-muted"></i>Department
                                </label>
                                <select class="form-control" id="department" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>" 
                                                <?php echo $departmentFilter === $dept ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="employment_status" class="form-label">
                                    <i class="fas fa-user-tag me-1 text-muted"></i>Employment Status
                                </label>
                                <select class="form-control" id="employment_status" name="employment_status">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($employmentStatuses as $es): ?>
                                        <option value="<?php echo htmlspecialchars($es); ?>" 
                                                <?php echo $employmentStatusFilter === $es ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($es); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 d-flex align-items-end">
                                <a href="employee_logs.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-redo me-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Employee Logs Table -->
                <div class="card">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                        <h5 class="mb-0 d-flex flex-wrap align-items-center gap-2">
                            <i class="fas fa-table text-primary"></i>
                            <span>Employee Logs</span>
                            <span id="employeeLogsBadges">
                                <span class="badge bg-primary"><?php echo $pagination['total']; ?> total</span>
                                <?php if ($pagination['totalPages'] > 1): ?>
                                    <span class="badge bg-info">Page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?></span>
                                <?php endif; ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body" id="employeeLogsCardBody">
                        <div id="employeeLogsResults">
                        <?php if (empty($employees)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <span class="empty-title">No Employees Found</span>
                                <p class="mb-0">No employees match your current filters. Try adjusting your search criteria.</p>
                                <?php if ($search || $positionFilter || $departmentFilter || $employmentStatusFilter): ?>
                                    <a href="employee_logs.php" class="btn btn-outline-primary mt-3">
                                        <i class="fas fa-redo me-1"></i> Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive-scroll">
                                <table class="table align-middle" id="employeesTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Safe Employee ID</th>
                                            <th>Name</th>
                                            <th>Position</th>
                                            <th>Department</th>
                                            <th>Salary Grade</th>
                                            <th>Monthly Rate</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="employeesTableBody">
                                        <?php 
                                        $counter = 1;
                                        foreach ($employees as $employee): 
                                            $fullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
                                            $annualSalary = floatval($employee['annual_salary'] ?? 0);
                                            $position = $employee['position'] ?? '';
                                            $empId = $employee['employee_id'] ?? '';
                                            $employmentType = $employee['employment_type'] ?? '';
                                            
                                            // Calculate monthly rate using same logic as calculate_salary_api.php
                                            $monthlyRate = 0;
                                            if ($annualSalary > 0) {
                                                // Check if the value looks like a monthly salary (less than 200,000) and convert to annual
                                                // Typical monthly salaries are 20k-100k, annual should be 240k-1.2M+
                                                if ($annualSalary < 200000) {
                                                    $annualSalary = $annualSalary * 12;
                                                }
                                                $monthlyRate = $annualSalary / 12;
                                            } else if ($position) {
                                                // Fallback: try positions table for monthly_rate
                                                $fallbackStmt = $db->prepare("SELECT monthly_rate FROM positions WHERE position_name = ? LIMIT 1");
                                                $fallbackStmt->execute([$position]);
                                                $posRow = $fallbackStmt->fetch();
                                                
                                                if ($posRow && !empty($posRow['monthly_rate'])) {
                                                    $monthlyRate = floatval($posRow['monthly_rate']);
                                                } else {
                                                    // Final fallback rates (as monthly rate)
                                                    if (stripos($position, 'Instructor 1') !== false) {
                                                        $monthlyRate = 19300;
                                                    } elseif (stripos($position, 'Instructor 2') !== false) {
                                                        $monthlyRate = 21000;
                                                    } elseif (stripos($position, 'Instructor 3') !== false) {
                                                        $monthlyRate = 23000;
                                                    }
                                                }
                                            }
                                        ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <?php if ($empId): ?>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars($empId); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Not assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($fullName); ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($position): ?>
                                                        <strong><?php echo htmlspecialchars($position); ?></strong>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not specified</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($employee['department'] ?: 'Not specified'); ?>
                                                </td>
                                                <td>
                                                    <?php if ($employee['salary_grade']): ?>
                                                        <span class="badge bg-info">SG-<?php echo htmlspecialchars($employee['salary_grade']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($monthlyRate > 0): ?>
                                                        <strong class="text-primary">₱<?php echo number_format($monthlyRate, 2); ?></strong>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not available</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($empId): ?>
                                                        <div class="action-buttons-container">
                                                            <button onclick="viewLogs('<?php echo htmlspecialchars($empId); ?>', '<?php echo htmlspecialchars(addslashes($fullName)); ?>')" class="btn btn-primary btn-sm" title="View Attendance Logs">
                                                                <i class="fas fa-clock"></i>
                                                                <span class="d-none d-md-inline">DTR</span>
                                                            </button>
                                                            <button onclick="if(typeof window.manageDeductions === 'function') { window.manageDeductions('<?php echo htmlspecialchars($empId); ?>', '<?php echo htmlspecialchars(addslashes($fullName)); ?>', '<?php echo htmlspecialchars(addslashes($position)); ?>', '<?php echo htmlspecialchars(addslashes($employmentType)); ?>'); } else { alert('Function not loaded. Please refresh the page.'); console.error('manageDeductions not found. Available:', typeof window.manageDeductions, typeof manageDeductions); }" class="btn btn-purple btn-sm" title="Manage Deductions">
                                                                <i class="fas fa-list"></i>
                                                                <span class="d-none d-md-inline">Deductions</span>
                                                            </button>
                                                            <button onclick="if(typeof window.viewSalary === 'function') { window.viewSalary('<?php echo htmlspecialchars($empId); ?>', '<?php echo htmlspecialchars(addslashes($fullName)); ?>', '<?php echo htmlspecialchars(addslashes($position)); ?>'); } else { alert('Function not loaded. Please refresh the page.'); console.error('viewSalary not found. Available:', typeof window.viewSalary, typeof viewSalary); }" class="btn btn-success btn-sm" title="View Salary Information">
                                                                <i class="fas fa-money-bill"></i>
                                                                <span class="d-none d-md-inline">Salary</span>
                                                            </button>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted small">
                                                            <i class="fas fa-info-circle me-1"></i>N/A
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($pagination['totalPages'] > 1): ?>
                                <div class="mt-3 employee-logs-pagination">
                                    <?php echo renderPagination($pagination['page'], $pagination['totalPages']); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Combined Employee Management Modal (DTR & Official Times) -->
    <div class="modal fade" id="employeeManagementModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen-lg-down" style="max-width: 95vw;">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0" id="employeeManagementModalTitle">Employee Management</h5>
                        <p class="text-muted small mb-0" id="employeeManagementEmployeeName"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Bootstrap Tabs -->
                    <ul class="nav nav-tabs mb-3" id="employeeManagementTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="dtr-tab" data-bs-toggle="tab" data-bs-target="#dtr-pane" type="button" role="tab" aria-controls="dtr-pane" aria-selected="true">
                                <i class="fas fa-calendar-check"></i> DTR
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="time-tab" data-bs-toggle="tab" data-bs-target="#time-pane" type="button" role="tab" aria-controls="time-pane" aria-selected="false">
                                <i class="fas fa-clock"></i> TIME
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="employeeManagementTabContent">
                        <!-- DTR Tab -->
                        <div class="tab-pane fade show active" id="dtr-pane" role="tabpanel" aria-labelledby="dtr-tab">
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <label class="form-label">Date From</label>
                                    <input type="date" id="filterDateFrom" class="form-control form-control-sm" onchange="applyDateRangeFilter()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Date To</label>
                                    <input type="date" id="filterDateTo" class="form-control form-control-sm" onchange="applyDateRangeFilter()">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button onclick="resetFilters()" class="btn btn-secondary btn-sm w-100">Reset</button>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button onclick="refreshLogs()" class="btn btn-primary btn-sm w-100">
                                        <i class="fas fa-sync-alt"></i> Refresh
                                    </button>
                                </div>
                            </div>
                            
                            <div class="table-responsive" style="max-height: 500px; overflow-y: auto; overflow-x: auto;">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light sticky-top" style="position: sticky; top: 0; z-index: 10; background-color: #f8f9fa;">
                                        <tr>
                                            <th>#</th>
                                            <th>Log Date</th>
                                            <th>Time In</th>
                                            <th>Lunch Out</th>
                                            <th>Lunch In</th>
                                            <th>Time Out</th>
                                            <th>Hours</th>
                                            <th>Hours (Days)</th>
                                            <th>Tardiness (Hrs, Days)</th>
                                            <th>Undertime (Hrs, Days)</th>
                                            <th>Absent (Hrs, Days)</th>
                                            <th>Tardiness & Undertime</th>
                                            <th>Tardiness/Absent/Late (Days)</th>
                                            <th>OT IN</th>
                                            <th>OT OUT</th>
                                            <th>TOTAL OT</th>
                                            <th>Status</th>
                                            <th>Verified</th>
                                            <th id="pardonColumnHeader" style="display: none;">Pardon</th>
                                        </tr>
                                    </thead>
                                    <tbody id="logsTableBody">
                                        <tr>
                                            <td colspan="19" class="text-center text-muted py-4">Loading attendance logs...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- TIME Tab -->
                        <div class="tab-pane fade" id="time-pane" role="tabpanel" aria-labelledby="time-tab">
                            <div class="alert alert-warning border-0 mb-3" id="editModeAlert" style="display: none; border-radius: 0px; transition: opacity 0.3s ease-out; opacity: 0;">
                                <i class="fas fa-edit me-2"></i><strong>Edit Mode Active:</strong> You are editing existing official times. Changes will update the selected entries.
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" id="officialTimesStartDate" class="form-control" required="">
                                    <small class="text-muted">Official times become effective from this date</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End Date</label>
                                    <input type="date" id="officialTimesEndDate" class="form-control">
                                    <small class="text-muted">Leave blank if ongoing (no end date)</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Select Weekdays <span class="text-danger">*</span></label>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="btn-group" role="group" style="display: flex; flex-wrap: wrap; gap: 5px;">
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-monday" value="Monday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-monday">Monday</label>
                                            
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-tuesday" value="Tuesday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-tuesday">Tuesday</label>
                                            
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-wednesday" value="Wednesday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-wednesday">Wednesday</label>
                                            
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-thursday" value="Thursday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-thursday">Thursday</label>
                                            
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-friday" value="Friday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-friday">Friday</label>
                                            
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-saturday" value="Saturday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-saturday">Saturday</label>
                                            
                                            <input type="checkbox" class="btn-check weekday-checkbox" id="weekday-sunday" value="Sunday" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="weekday-sunday">Sunday</label>
                                        </div>
                                        <small class="text-muted d-block mt-2">Select the weekdays to set official times for. Each weekday will have its own tab below.</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="weekdayTimesContainer">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Please select weekdays above, then use the tabs below to set official times for each weekday.
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i> Official times will be used for calculating late, undertime, and overtime for this employee during the specified date range for each selected weekday.
                            </div>
                            
                            <div id="officialTimesHistory" class="mt-4">
                                <h6>Recent Official Times</h6>
                                <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Weekday</th>
                                                <th>Time In</th>
                                                <th>Lunch Out</th>
                                                <th>Lunch In</th>
                                                <th>Time Out</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="officialTimesHistoryBody">
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-3" data-label="Start Date">No official times set yet</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- DTR Tab Footer Buttons -->
                    <div id="dtr-footer-buttons">
                        <button onclick="printDTR()" class="btn btn-success" id="printDTRBtnFooter" disabled>
                            <i class="fas fa-print"></i> Print DTR
                        </button>
                        <button onclick="viewSalaryReports()" class="btn btn-info">
                            <i class="fas fa-money-bill-wave"></i> View Salary Reports
                        </button>
                    </div>
                    
                    <!-- TIME Tab Footer Buttons -->
                    <div id="time-footer-buttons" style="display: none;">
                        <div id="editModeIndicator" class="me-auto" style="display: none; transition: opacity 0.3s ease-out; opacity: 0;">
                            <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                                <i class="fas fa-edit"></i> <strong>Edit Mode</strong> - Editing existing official times
                            </span>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="window.cancelEditMode()" id="cancelEditBtn" style="display: none;">
                            <i class="fas fa-times"></i> Cancel Edit
                        </button>
                        <button type="button" class="btn btn-primary" id="saveOfficialTimesBtn" onclick="if(typeof window.saveOfficialTimes === 'function') { window.saveOfficialTimes(); } else { alert('Function not loaded. Please refresh the page.'); console.error('saveOfficialTimes not found'); }">
                            <i class="fas fa-save"></i> <span id="saveButtonText">Save Official Times</span>
                        </button>
                    </div>
                    
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Print DTR Modal -->
    <div class="modal fade" id="bulkPrintDTRModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Print All Employees DTR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will print DTRs for all employees matching your current filters. Each employee's DTR will be on a separate page.
                    </div>
                    <div class="mb-3">
                        <label for="bulkPrintDateFrom" class="form-label">Date From <span class="text-danger">*</span></label>
                        <input type="date" id="bulkPrintDateFrom" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="bulkPrintDateTo" class="form-label">Date To <span class="text-danger">*</span></label>
                        <input type="date" id="bulkPrintDateTo" class="form-control" required>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> The print will include all employees currently visible based on your search, position, department, and employment status filters.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="printAllDTR()">
                        <i class="fas fa-print me-1"></i> Print All DTRs
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Employee Deductions Modal -->
    <div class="modal fade" id="deductionsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deductionsModalTitle">Employee Deductions</h5>
                    <p class="text-muted mb-0 small" id="deductionsEmployeeName"></p>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <button onclick="openAddDeductionForm('tardiness')" class="btn btn-danger btn-sm">
                                <i class="fas fa-clock"></i> Add Tardiness
                            </button>
                            <button id="philHealthButton" onclick="openAddPhilHealthDeduction()" class="btn btn-info btn-sm" title="Add PhilHealth Deduction">
                                <i class="fas fa-heartbeat"></i> Add PhilHealth
                            </button>
                            <div class="vr mx-2"></div>
                            <button onclick="openAddDeductionForm()" class="btn btn-success btn-sm">
                                <i class="fas fa-plus"></i> Add Other Deduction
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-sm table-hover">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Deduction Item</th>
                                    <th>Type</th>
                                    <th class="text-end">Amount</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="employeeDeductionsBody">
                                <!-- Employee deductions will load here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Deduction Form Modal -->
    <div class="modal fade" id="addDeductionFormModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addDeductionModalTitle">Add Deduction to Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addDeductionForm" method="POST" onsubmit="return submitDeductionForm(event);">
                    <div class="modal-body">
                        <input type="hidden" name="employee_id" id="deductionEmployeeId">
                        <input type="hidden" name="ed_id" id="deductionEdId" value="0">
                        <input type="hidden" name="add_employee_deduction" value="1">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="is_tardiness" id="is_tardiness" value="0">
                        <input type="hidden" name="deduction_id" id="deduction_id" value="">

                        <!-- Tardiness fields (shown when deductionType is 'tardiness') -->
                        <div id="tardinessFields">
                            <div class="alert alert-info mb-3">
                                <small><strong>How it works:</strong> Enter hours of tardiness, it will be multiplied by 2, then calculated based on the employee's hourly rate.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Hour of Tardiness</label>
                                <div class="row g-2">
                                    <div class="col-4">
                                        <label class="form-label small text-muted">Hours</label>
                                        <input type="number" id="tardinessHoursInput" min="0" max="23" step="1" class="form-control" placeholder="0" oninput="updateTardinessTime(); calculateTardinessDeduction()" value="0">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small text-muted">Minutes</label>
                                        <input type="number" id="tardinessMinutesInput" min="0" max="59" step="1" class="form-control" placeholder="0" oninput="updateTardinessTime(); calculateTardinessDeduction()" value="0">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small text-muted">Seconds</label>
                                        <input type="number" id="tardinessSecondsInput" min="0" max="59" step="1" class="form-control" placeholder="0" oninput="updateTardinessTime(); calculateTardinessDeduction()" value="0">
                                    </div>
                                </div>
                                <input type="hidden" id="tardinessHours" value="00:00:00">
                                <small class="text-muted">Enter hours, minutes, and seconds. Hours will be multiplied by 2 before calculating deduction</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Hourly Rate (₱)</label>
                                <input type="text" id="tardinessHourlyRate" readonly class="form-control bg-light" placeholder="Will be fetched automatically">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Deduction Amount (₱)</label>
                                <input type="text" name="amount" id="deductionAmount" readonly class="form-control bg-light fw-bold text-danger" placeholder="0.00" required>
                                <small class="text-muted">Formula: (Hours × 2) × Hourly Rate</small>
                            </div>
                        </div>

                        <!-- Other Deduction fields (shown when deductionType is not 'tardiness') -->
                        <div id="otherDeductionFields" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Select Deduction Item</label>
                                <select name="deduction_id_select" id="deduction_id_select" class="form-control" required>
                                    <option value="">Choose a deduction...</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Amount to Deduct (₱)</label>
                                <input type="number" name="amount_other" id="deductionAmountOther" step="0.01" min="0" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="deductionStartDate" required class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">End Date (Optional)</label>
                            <input type="date" name="end_date" id="deductionEndDate" class="form-control" placeholder="Leave blank if ongoing">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks (Optional)</label>
                            <textarea name="remarks" id="deductionRemarks" rows="2" class="form-control"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button id="deductionSubmitButton" type="submit" class="btn btn-success">Add Deduction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Salary Modal -->
    <div class="modal fade" id="salaryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="salaryModalTitle">Salary Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Employee Info & Controls -->
                    <div class="row mb-3 pb-3 border-bottom">
                        <div class="col-md-4">
                            <label class="text-muted small mb-1">Employee Name</label>
                            <p class="mb-0 fw-semibold" id="salaryEmpName">-</p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small mb-1">Position</label>
                            <p class="mb-0 fw-semibold" id="salaryPosition">-</p>
                        </div>
                        <div class="col-md-4">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small">Date From</label>
                                    <input type="date" id="salaryDateFrom" class="form-control form-control-sm" onchange="calculateSalary()">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Date To</label>
                                    <input type="date" id="salaryDateTo" class="form-control form-control-sm" onchange="calculateSalary()">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hours Summary -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="text-muted small mb-2 d-block">Hours Summary</label>
                            <div class="row g-2">
                                <div class="col-3">
                                    <div class="p-2 bg-light rounded text-center">
                                        <p class="text-muted small mb-1">Total Hours</p>
                                        <p class="h5 mb-0 text-primary" id="totalHours">0.00</p>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="p-2 bg-light rounded text-center">
                                        <p class="text-muted small mb-1">Total Hours (Days)</p>
                                        <p class="h5 mb-0 text-primary" id="totalHoursDays">0.000</p>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="p-2 bg-light rounded text-center">
                                        <p class="text-muted small mb-1">Tardiness (Hrs / Days)</p>
                                        <p class="h5 mb-0 text-danger" id="totalLate">0.00</p>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="p-2 bg-light rounded text-center">
                                        <p class="text-muted small mb-1">Undertime (Hrs / Days)</p>
                                        <p class="h5 mb-0 text-warning" id="totalUndertime">0.00</p>
                                    </div>
                                </div>
                                <!-- Overtime removed - now tracked as COC (Credits of Compensation) in employee profile -->
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-3">
                                    <div class="p-2 bg-light rounded text-center">
                                        <p class="text-muted small mb-1">Tardiness/Absent/Late (Days)</p>
                                        <p class="h5 mb-0 text-warning" id="totalTardinessAbsentLateDays">0.000</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Rates & Deductions -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="card border">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">Salary Rates</h6>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Annual Salary</span>
                                        <span class="fw-semibold">₱ <span id="annualSalary">0.00</span></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Monthly Rate</span>
                                        <span class="fw-semibold">₱ <span id="monthlyRate">0.00</span></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">
                                            <strong>Weekly Rate</strong>
                                            <small class="d-block text-muted" style="font-size: 0.75rem; font-weight: normal;">(40 hrs/week target)</small>
                                        </span>
                                        <span class="fw-semibold text-primary">₱ <span id="weeklyRate">0.00</span></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Daily Rate</span>
                                        <span class="fw-semibold">₱ <span id="dailyRate">0.00</span></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-0">
                                        <span class="text-muted">Hourly Rate</span>
                                        <span class="fw-semibold">₱ <span id="hourlyRate">0.00</span></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border">
                                <div class="card-body">
                                    <h6 class="card-title mb-3">Adjustments</h6>
                                    <!-- All deductions (tardiness, undertime, absence) are now added manually in the deduction modal -->
                                    <!-- They will appear in the additional deductions section below -->
                                    <div id="additionalDeductionsContainer">
                                        <!-- All deductions (including tardiness, undertime, absence) will be displayed here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="salary-summary-box gross">
                                <p class="text-muted small mb-1">Gross Salary</p>
                                <p class="h4 mb-0 text-primary">₱ <span id="grossSalary">0.00</span></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="salary-summary-box deductions">
                                <p class="text-muted small mb-1">Total Deductions</p>
                                <p class="h4 mb-0 text-danger" id="totalDeductionsBox">₱ 0.00</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="salary-summary-box net">
                                <p class="text-muted small mb-1">Net Income</p>
                                <p class="h4 mb-0 text-success">₱ <span id="netIncome">0.00</span></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button onclick="generatePayslip()" class="btn btn-success">Generate Payslip</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payslip Modal -->
    <div class="modal fade" id="payslipModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payslip Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding: 0;">
                    <div id="payslipContent" style="background: white; padding: 20px;"></div>
                </div>
                <div class="modal-footer">
                    <button onclick="printPayslip()" class="btn btn-primary">Print / Save</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Batch Calculation Modal -->
    <div class="modal fade" id="batchCalculationModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Batch Salary Calculation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-2 align-items-end g-2">
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label">Start date</label>
                            <input type="date" id="batchSalaryDateFrom" class="form-control">
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label">End date</label>
                            <input type="date" id="batchSalaryDateTo" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Employment Status</label>
                            <select id="batchSalaryEmploymentStatus" class="form-control">
                                <option value="">All Statuses</option>
                                <?php foreach ($employmentStatuses as $es): ?>
                                    <option value="<?php echo htmlspecialchars($es); ?>" <?php echo ($employmentStatusFilter === $es) ? 'selected' : ''; ?>><?php echo htmlspecialchars($es); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button onclick="calculateBatchSalaries()" class="btn btn-primary w-100">Calculate All</button>
                        </div>
                        <div class="col-md-2">
                            <button onclick="exportBatchSalaries()" class="btn btn-success w-100">
                                <i class="fas fa-download"></i> Export
                            </button>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="batchSalaryRequireAttendance" checked>
                                <label class="form-check-label" for="batchSalaryRequireAttendance">
                                    Only employees with at least one attendance entry in the selected date range (recommended). Uncheck to include everyone matching employment status who has a position, even with no logs in range.
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive overflow-auto" style="max-height: 60vh;">
                        <table class="table table-sm table-hover" style="min-width: 1520px;">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="min-width: 100px;">Emp ID</th>
                                    <th style="min-width: 150px;">Full Name</th>
                                    <th style="min-width: 120px;">Position</th>
                                    <th class="text-end" style="min-width: 90px;">Hours</th>
                                    <th class="text-end" style="min-width: 100px;">HOURS (DAY)</th>
                                    <th class="text-end" style="min-width: 80px;">Tardiness</th>
                                    <th class="text-end" style="min-width: 90px;">Undertime</th>
                                    <th class="text-end" style="min-width: 120px;">Tardiness/Late (Days)</th>
                                    <th class="text-end" style="min-width: 110px;">Absence (Days)</th>
                                    <!-- Overtime removed - now tracked as COC (Credits of Compensation) in employee profile -->
                                    <th class="text-end" style="min-width: 110px;">Gross</th>
                                    <th class="text-end" style="min-width: 120px;">Tardiness Ded.</th>
                                    <!-- OT Pay removed - overtime now tracked as COC (Credits of Compensation) in employee profile -->
                                    <th class="text-end" style="min-width: 110px;">Total Ded.</th>
                                    <th class="text-end" style="min-width: 110px;">Net Income</th>
                                </tr>
                            </thead>
                            <tbody id="batchSalaryTableBody">
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-4">Choose a date range and click "Calculate All" to generate salaries</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="batchSalaryPagination" class="d-none d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                        <div class="text-muted small">
                            <span id="batchSalaryPageInfo">Showing 0–0 of 0</span>
                        </div>
                        <nav>
                            <ul id="batchSalaryPaginationControls" class="pagination pagination-sm mb-0"></ul>
                        </nav>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>


    <!-- All Tardiness Modal -->
    <div class="modal fade" id="allTardinessModal" tabindex="-1" aria-labelledby="allTardinessModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="allTardinessModalLabel"><i class="fas fa-clock me-2"></i>All Employee Tardiness Records</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">From Date</label>
                            <input type="date" id="tardinessStartDateFilter" class="form-control form-control-sm" onchange="loadAllTardiness()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">To Date</label>
                            <input type="date" id="tardinessEndDateFilter" class="form-control form-control-sm" onchange="loadAllTardiness()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Safe Employee ID</label>
                            <input type="text" id="tardinessEmployeeFilter" class="form-control form-control-sm" placeholder="Filter by Safe Employee ID" onchange="loadAllTardiness()">
                        </div>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearTardinessFilters(); loadAllTardiness()">
                            <i class="fas fa-redo me-1"></i>Clear Filters
                        </button>
                        <span class="badge bg-info ms-2" id="tardinessCountBadge">0 records</span>
                    </div>
                    
                    <!-- Table -->
                    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>#</th>
                                    <th>Full Name</th>
                                    <th>Position</th>
                                    <th>Date</th>
                                    <th>Official Time</th>
                                    <th>Actual Time In</th>
                                    <th class="text-end">Tardiness</th>
                                </tr>
                            </thead>
                            <tbody id="allTardinessTableBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading tardiness records...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="generateTardinessReport()">
                        <i class="fas fa-file-export me-1"></i>Create Report
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script>
        // Bulk Print DTR Functions
        function openBulkPrintDTRModal() {
            const modalElement = document.getElementById('bulkPrintDTRModal');
            if (!modalElement) {
                alert('Modal element not found. Please refresh the page.');
                return;
            }
            
            // Set default date range (current month)
            const now = new Date();
            const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
            const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            
            const dateFromInput = document.getElementById('bulkPrintDateFrom');
            const dateToInput = document.getElementById('bulkPrintDateTo');
            
            if (dateFromInput && !dateFromInput.value) {
                dateFromInput.value = firstDay.toISOString().split('T')[0];
            }
            if (dateToInput && !dateToInput.value) {
                dateToInput.value = lastDay.toISOString().split('T')[0];
            }
            
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
        
        function printAllDTR() {
            const dateFrom = document.getElementById('bulkPrintDateFrom').value;
            const dateTo = document.getElementById('bulkPrintDateTo').value;
            
            if (!dateFrom || !dateTo) {
                alert('Please select both start and end dates.');
                return;
            }
            
            if (dateFrom > dateTo) {
                alert('Start date must be before or equal to end date.');
                return;
            }
            
            // Get current filter values from the page
            const search = document.getElementById('search')?.value || '';
            const position = document.getElementById('position')?.value || '';
            const department = document.getElementById('department')?.value || '';
            const employmentStatus = document.getElementById('employment_status')?.value || '';
            
            // Build URL with all parameters
            let url = 'print_all_dtr?date_from=' + encodeURIComponent(dateFrom) + 
                     '&date_to=' + encodeURIComponent(dateTo);
            
            if (search) {
                url += '&search=' + encodeURIComponent(search);
            }
            if (position) {
                url += '&position=' + encodeURIComponent(position);
            }
            if (department) {
                url += '&department=' + encodeURIComponent(department);
            }
            if (employmentStatus) {
                url += '&employment_status=' + encodeURIComponent(employmentStatus);
            }
            
            // Close modal
            const modalElement = document.getElementById('bulkPrintDTRModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
            
            // Open print page in new window
            window.open(url, '_blank');
        }
        
        // Define openAllTardinessModal FIRST to ensure it's always available
        // This will be overridden by the external script if it loads successfully
        window.openAllTardinessModal = function() {
            console.log('openAllTardinessModal called (inline version)');
            
            const modalElement = document.getElementById('allTardinessModal');
            if (!modalElement) {
                console.error('allTardinessModal element not found');
                alert('Modal element not found. Please refresh the page.');
                return;
            }
            
            // Check if Bootstrap is available
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                console.error('Bootstrap Modal not available');
                alert('Bootstrap is not loaded. Please refresh the page.');
                return;
            }
            
            try {
                // Use Bootstrap's getOrCreateInstance for better modal handling
                let modalInstance;
                if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                    modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    });
                } else {
                    modalInstance = new bootstrap.Modal(modalElement, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    });
                }
                
                // Set default date filters (current month)
                const now = new Date();
                const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
                const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                
                const startDateInput = document.getElementById('tardinessStartDateFilter');
                const endDateInput = document.getElementById('tardinessEndDateFilter');
                
                if (startDateInput && !startDateInput.value) {
                    startDateInput.value = firstDay.toISOString().split('T')[0];
                }
                if (endDateInput && !endDateInput.value) {
                    endDateInput.value = lastDay.toISOString().split('T')[0];
                }
                
                // Show modal
                modalInstance.show();
                
                // Load tardiness records after modal is shown
                modalElement.addEventListener('shown.bs.modal', function loadData() {
                    // Wait a bit for external script to load, then try
                    setTimeout(function() {
                        if (typeof window.loadAllTardiness === 'function') {
                            window.loadAllTardiness();
                        } else {
                            console.warn('loadAllTardiness not found, will be available after external script loads');
                            // Retry after external script should have loaded
                            setTimeout(function() {
                                if (typeof window.loadAllTardiness === 'function') {
                                    window.loadAllTardiness();
                                } else {
                                    console.error('loadAllTardiness function still not found after retry');
                                }
                            }, 500);
                        }
                    }, 100);
                }, { once: true });
                
            } catch (error) {
                console.error('Error opening tardiness modal:', error);
                alert('Error opening modal: ' + error.message);
            }
        };
        console.log('openAllTardinessModal defined inline');
    </script>
    <script>
        // Define deleteOfficialTime inline to ensure it's always available
        window.deleteOfficialTime = function(id) {
            console.log('deleteOfficialTime called with id:', id);
            if (!confirm('Are you sure you want to delete this official time?')) {
                return;
            }
            
            const employeeId = window.currentOfficialTimesEmpId;
            if (!employeeId) {
                alert('Error: Safe Employee ID not set');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('employee_id', employeeId);
            formData.append('id', id);
            
            fetch('manage_official_times_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                console.log('Delete raw response:', text);
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        if (typeof showToast === 'function') {
                            showToast(data.message || 'Official times deleted successfully', 'success');
                        } else {
                            alert(data.message || 'Official times deleted successfully');
                        }
                        // Reload history
                        const empId = window.currentOfficialTimesEmpId;
                        if (empId) {
                            fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(empId)}`)
                                .then(response => response.json())
                                .then(historyData => {
                                    const tbody = document.getElementById('officialTimesHistoryBody');
                                    if (tbody) {
                                        tbody.innerHTML = '';
                                        if (historyData.success && historyData.official_times && historyData.official_times.length > 0) {
                                            historyData.official_times.forEach(ot => {
                                                const row = document.createElement('tr');
                                                row.innerHTML = `
                                                    <td>${ot.start_date || '-'}</td>
                                                    <td>${ot.end_date || '<span class="text-success">Ongoing</span>'}</td>
                                                    <td>${ot.weekday || '-'}</td>
                                                    <td>${ot.time_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_in) : ot.time_in) : '-'}</td>
                                                    <td>${ot.lunch_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_out) : ot.lunch_out) : '-'}</td>
                                                    <td>${ot.lunch_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_in) : ot.lunch_in) : '-'}</td>
                                                    <td>${ot.time_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_out) : ot.time_out) : '-'}</td>
                                                    <td>
                                                        <button onclick="window.loadOfficialTime('${ot.start_date}', '${ot.weekday || ''}')" class="btn btn-sm btn-primary" title="Edit">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button onclick="window.deleteOfficialTime(${ot.id})" class="btn btn-sm btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                `;
                                                tbody.appendChild(row);
                                            });
                                        } else {
                                            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No official times set yet</td></tr>';
                                        }
                                    }
                                })
                                .catch(error => console.error('Error reloading history:', error));
                        }
                    } else {
                        alert(data.message || 'Error deleting official times');
                    }
                } catch (e) {
                    console.error('Error parsing delete response:', e);
                    alert('Error deleting official times');
                }
            })
            .catch(error => {
                console.error('Error deleting official times:', error);
                alert('Error deleting official times: ' + error.message);
            });
        };
        
        // Store IDs of entries being edited
        window.editingOfficialTimeIds = {};
        
        // Store original form values for change detection
        window.originalOfficialTimeValues = {};
        
        // Function to capture current form state
        window.captureOriginalValues = function() {
            const startDate = document.getElementById('officialTimesStartDate')?.value || '';
            const endDate = document.getElementById('officialTimesEndDate')?.value || '';
            const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const originalValues = {
                start_date: startDate,
                end_date: endDate,
                weekdays: {}
            };
            
            weekdays.forEach(day => {
                const dayLower = day.toLowerCase();
                const checkbox = document.getElementById('weekday-' + dayLower);
                const timeIn = document.getElementById('time_in_' + dayLower)?.value || '';
                const timeOut = document.getElementById('time_out_' + dayLower)?.value || '';
                const lunchOut = document.getElementById('lunch_out_' + dayLower)?.value || '';
                const lunchIn = document.getElementById('lunch_in_' + dayLower)?.value || '';
                
                originalValues.weekdays[day] = {
                    checked: checkbox?.checked || false,
                    time_in: timeIn,
                    time_out: timeOut,
                    lunch_out: lunchOut,
                    lunch_in: lunchIn
                };
            });
            
            window.originalOfficialTimeValues = originalValues;
        };
        
        // Function to check if form has changes
        window.hasFormChanges = function() {
            if (!window.originalOfficialTimeValues || Object.keys(window.originalOfficialTimeValues).length === 0) {
                return false; // No original values stored, so no changes
            }
            
            const startDate = document.getElementById('officialTimesStartDate')?.value || '';
            const endDate = document.getElementById('officialTimesEndDate')?.value || '';
            
            // Check date changes
            if (startDate !== window.originalOfficialTimeValues.start_date) {
                return true;
            }
            if (endDate !== window.originalOfficialTimeValues.end_date) {
                return true;
            }
            
            // Check weekday changes
            const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            for (const day of weekdays) {
                const dayLower = day.toLowerCase();
                const checkbox = document.getElementById('weekday-' + dayLower);
                const timeIn = document.getElementById('time_in_' + dayLower)?.value || '';
                const timeOut = document.getElementById('time_out_' + dayLower)?.value || '';
                const lunchOut = document.getElementById('lunch_out_' + dayLower)?.value || '';
                const lunchIn = document.getElementById('lunch_in_' + dayLower)?.value || '';
                
                const original = window.originalOfficialTimeValues.weekdays[day] || {};
                
                if ((checkbox?.checked || false) !== (original.checked || false)) {
                    return true;
                }
                if (timeIn !== (original.time_in || '')) {
                    return true;
                }
                if (timeOut !== (original.time_out || '')) {
                    return true;
                }
                if (lunchOut !== (original.lunch_out || '')) {
                    return true;
                }
                if (lunchIn !== (original.lunch_in || '')) {
                    return true;
                }
            }
            
            return false;
        };
        
        // Function to update save button text based on changes
        window.updateSaveButtonText = function() {
            const saveButtonText = document.getElementById('saveButtonText');
            if (!saveButtonText) return;
            
            // Only update if in edit mode
            if (window.editingOfficialTimeIds && Object.keys(window.editingOfficialTimeIds).length > 0) {
                if (window.hasFormChanges()) {
                    saveButtonText.textContent = 'Save Changes';
                } else {
                    saveButtonText.textContent = 'Save Official Times';
                }
            }
        };
        
        // Function to attach change listeners to form fields
        window.attachChangeListeners = function() {
            const startDateEl = document.getElementById('officialTimesStartDate');
            const endDateEl = document.getElementById('officialTimesEndDate');
            const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            
            // Remove existing listeners if any (to prevent duplicates)
            if (startDateEl) {
                startDateEl.removeEventListener('change', window.updateSaveButtonText);
                startDateEl.addEventListener('change', window.updateSaveButtonText);
            }
            if (endDateEl) {
                endDateEl.removeEventListener('change', window.updateSaveButtonText);
                endDateEl.addEventListener('change', window.updateSaveButtonText);
            }
            
            weekdays.forEach(day => {
                const dayLower = day.toLowerCase();
                const checkbox = document.getElementById('weekday-' + dayLower);
                const timeIn = document.getElementById('time_in_' + dayLower);
                const timeOut = document.getElementById('time_out_' + dayLower);
                const lunchOut = document.getElementById('lunch_out_' + dayLower);
                const lunchIn = document.getElementById('lunch_in_' + dayLower);
                
                if (checkbox) {
                    checkbox.removeEventListener('change', window.updateSaveButtonText);
                    checkbox.addEventListener('change', window.updateSaveButtonText);
                }
                if (timeIn) {
                    timeIn.removeEventListener('change', window.updateSaveButtonText);
                    timeIn.addEventListener('input', window.updateSaveButtonText);
                }
                if (timeOut) {
                    timeOut.removeEventListener('change', window.updateSaveButtonText);
                    timeOut.addEventListener('input', window.updateSaveButtonText);
                }
                if (lunchOut) {
                    lunchOut.removeEventListener('change', window.updateSaveButtonText);
                    lunchOut.addEventListener('input', window.updateSaveButtonText);
                }
                if (lunchIn) {
                    lunchIn.removeEventListener('change', window.updateSaveButtonText);
                    lunchIn.addEventListener('input', window.updateSaveButtonText);
                }
            });
        };
        
        // Define loadOfficialTime inline - enters edit mode
        window.loadOfficialTime = function(startDate, weekday) {
            console.log('loadOfficialTime called with:', startDate, weekday);
            const startDateElement = document.getElementById('officialTimesStartDate');
            const endDateElement = document.getElementById('officialTimesEndDate');
            
            if (!startDateElement) {
                console.error('Start date element not found');
                return;
            }
            
            if (startDateElement) {
                startDateElement.value = startDate;
            }
            
            // Load the official time data - get ALL weekdays with this start_date
            const employeeId = window.currentOfficialTimesEmpId;
            if (employeeId && startDate) {
                // First, get all official times for this start_date
                fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(employeeId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.official_times) {
                            // Filter to get all times with this start_date
                            const timesForStartDate = data.official_times.filter(ot => ot.start_date === startDate);
                            
                            if (timesForStartDate.length > 0) {
                                // Clear previous edit IDs
                                window.editingOfficialTimeIds = {};
                                
                                // Use the first one's end_date (they should all have the same end_date)
                                const firstTime = timesForStartDate[0];
                            if (endDateElement) {
                                    endDateElement.value = firstTime.end_date || '';
                                }
                                
                                // Check all weekday checkboxes and load their times
                                const weekdaysToLoad = [];
                                
                                timesForStartDate.forEach(ot => {
                                    const dayName = ot.weekday || 'Monday';
                                    // Store the ID for this weekday for edit mode (ensure it's a number)
                                    window.editingOfficialTimeIds[dayName] = parseInt(ot.id) || ot.id;
                                    console.log(`Loaded ${dayName} with ID: ${window.editingOfficialTimeIds[dayName]} for editing`);
                                    
                                    weekdaysToLoad.push({
                                        weekday: dayName,
                                        id: ot.id,
                                        time_in: ot.time_in || '08:00',
                                        time_out: ot.time_out || '17:00',
                                        lunch_out: ot.lunch_out && ot.lunch_out !== '-' ? ot.lunch_out : '',
                                        lunch_in: ot.lunch_in && ot.lunch_in !== '-' ? ot.lunch_in : ''
                                    });
                                    
                                    // Check the weekday checkbox
                                    const weekdayCheckbox = document.getElementById('weekday-' + dayName.toLowerCase());
                                if (weekdayCheckbox) {
                                    weekdayCheckbox.checked = true;
                                }
                                });
                                
                                // Update the weekday inputs to create tabs
                                if (typeof window.updateWeekdayTimeInputs === 'function') {
                                    window.updateWeekdayTimeInputs();
                                }
                                
                                // Load times for each weekday after tabs are created
                                setTimeout(() => {
                                    weekdaysToLoad.forEach(ot => {
                                        const dayLower = ot.weekday.toLowerCase();
                                    const timeInEl = document.getElementById('time_in_' + dayLower);
                                    const timeOutEl = document.getElementById('time_out_' + dayLower);
                                    const lunchOutEl = document.getElementById('lunch_out_' + dayLower);
                                    const lunchInEl = document.getElementById('lunch_in_' + dayLower);
                                    
                                        if (timeInEl) timeInEl.value = ot.time_in;
                                        if (timeOutEl) timeOutEl.value = ot.time_out;
                                        if (lunchOutEl) lunchOutEl.value = ot.lunch_out || '';
                                        if (lunchInEl) lunchInEl.value = ot.lunch_in || '';
                                    });
                                    
                                    // Activate the first tab if weekday was specified
                                    if (weekday) {
                                        const dayLower = weekday.toLowerCase();
                                        const tabButton = document.getElementById('tab-' + dayLower);
                                        if (tabButton) {
                                            const tab = new bootstrap.Tab(tabButton);
                                            tab.show();
                                        }
                                    }
                                    
                                    // Capture original values after all fields are loaded
                                    window.captureOriginalValues();
                                    
                                    // Attach change listeners
                                    window.attachChangeListeners();
                                    
                                    // Update button text initially (no changes yet)
                                    window.updateSaveButtonText();
                                }, 500);
                                
                                // Enter edit mode
                                window.enterEditMode();
                            } else {
                                // No times found for this start_date, just set the date
                                if (endDateElement) {
                                    endDateElement.value = '';
                                }
                                // Exit edit mode if no data found
                                window.exitEditMode();
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading official times:', error);
                        window.exitEditMode();
                    });
            }
        };
        
        // Enter edit mode
        window.enterEditMode = function() {
            const editIndicator = document.getElementById('editModeIndicator');
            const editModeAlert = document.getElementById('editModeAlert');
            const modalHeader = document.querySelector('#employeeManagementModal .modal-header');
            const cancelBtn = document.getElementById('cancelEditBtn');
            const saveBtn = document.getElementById('saveOfficialTimesBtn');
            const saveButtonText = document.getElementById('saveButtonText');
            
            // Show edit mode alert banner
            if (editModeAlert) {
                editModeAlert.style.display = 'block';
                editModeAlert.style.opacity = '0';
                setTimeout(() => {
                    editModeAlert.style.transition = 'opacity 0.3s ease-in';
                    editModeAlert.style.opacity = '1';
                }, 10);
            }
            
            // Highlight modal header
            if (modalHeader) {
                modalHeader.style.borderLeft = '4px solid #ffc107';
                modalHeader.style.backgroundColor = '#fff3cd';
            }
            
            if (editIndicator) {
                editIndicator.style.display = 'block';
                // Add a subtle animation
                editIndicator.style.opacity = '0';
                setTimeout(() => {
                    editIndicator.style.transition = 'opacity 0.3s ease-in';
                    editIndicator.style.opacity = '1';
                }, 10);
            }
            if (cancelBtn) cancelBtn.style.display = 'inline-block';
            // Button text will be updated by updateSaveButtonText() based on changes
            if (saveButtonText) saveButtonText.textContent = 'Save Official Times';
            if (saveBtn) {
                saveBtn.classList.remove('btn-primary');
                saveBtn.classList.add('btn-success');
                // Add a subtle animation
                saveBtn.style.transform = 'scale(1.05)';
                setTimeout(() => {
                    saveBtn.style.transition = 'transform 0.2s ease-out';
                    saveBtn.style.transform = 'scale(1)';
                }, 200);
            }
        };
        
        // Exit edit mode
        window.exitEditMode = function() {
            const editIndicator = document.getElementById('editModeIndicator');
            const editModeAlert = document.getElementById('editModeAlert');
            const modalHeader = document.querySelector('#employeeManagementModal .modal-header');
            const cancelBtn = document.getElementById('cancelEditBtn');
            const saveBtn = document.getElementById('saveOfficialTimesBtn');
            const saveButtonText = document.getElementById('saveButtonText');
            
            // Hide edit mode alert banner
            if (editModeAlert) {
                editModeAlert.style.transition = 'opacity 0.3s ease-out';
                editModeAlert.style.opacity = '0';
                setTimeout(() => {
                    editModeAlert.style.display = 'none';
                }, 300);
            }
            
            // Reset modal header
            if (modalHeader) {
                modalHeader.style.borderLeft = '';
                modalHeader.style.backgroundColor = '';
            }
            
            if (editIndicator) {
                editIndicator.style.transition = 'opacity 0.3s ease-out';
                editIndicator.style.opacity = '0';
                setTimeout(() => {
                    editIndicator.style.display = 'none';
                }, 300);
            }
            if (cancelBtn) cancelBtn.style.display = 'none';
            if (saveButtonText) saveButtonText.textContent = 'Save Official Times';
            if (saveBtn) {
                saveBtn.classList.remove('btn-success');
                saveBtn.classList.add('btn-primary');
            }
            
            // Clear edit IDs and original values
            window.editingOfficialTimeIds = {};
            window.originalOfficialTimeValues = {};
        };
        
        // Cancel edit mode
        window.cancelEditMode = function() {
            if (confirm('Are you sure you want to cancel editing? All unsaved changes will be lost.')) {
                // Reset form
                const startDateElement = document.getElementById('officialTimesStartDate');
                const endDateElement = document.getElementById('officialTimesEndDate');
                
                if (startDateElement) {
                    const today = new Date();
                    startDateElement.value = today.toISOString().split('T')[0];
                }
                if (endDateElement) {
                    endDateElement.value = '';
                }
                
                // Uncheck all weekday checkboxes
                const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                weekdays.forEach(day => {
                    const checkbox = document.getElementById('weekday-' + day.toLowerCase());
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
                
                // Update weekday inputs to clear tabs
                if (typeof window.updateWeekdayTimeInputs === 'function') {
                    window.updateWeekdayTimeInputs();
                }
                
                // Exit edit mode
                window.exitEditMode();
            }
        };
        
        // Function to update weekday time inputs when weekdays are selected
        window.updateWeekdayTimeInputs = function() {
            const container = document.getElementById('weekdayTimesContainer');
            if (!container) return;
            
            const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const selectedWeekdays = [];
            
            weekdays.forEach(day => {
                const checkbox = document.getElementById('weekday-' + day.toLowerCase());
                if (checkbox && checkbox.checked) {
                    selectedWeekdays.push(day);
                }
            });
            
            if (selectedWeekdays.length === 0) {
                container.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> Please select weekdays above, then use the tabs below to set official times for each weekday.</div>';
                return;
            }
            
            // Create Bootstrap tabs
            let html = '<ul class="nav nav-tabs mb-3" id="weekdayTabs" role="tablist">';
            selectedWeekdays.forEach((day, index) => {
                const dayLower = day.toLowerCase();
                const isActive = index === 0 ? 'active' : '';
                html += `
                    <li class="nav-item" role="presentation">
                        <button class="nav-link ${isActive}" id="tab-${dayLower}" data-bs-toggle="tab" data-bs-target="#pane-${dayLower}" type="button" role="tab" aria-controls="pane-${dayLower}" aria-selected="${index === 0 ? 'true' : 'false'}">
                            ${day}
                        </button>
                    </li>
                `;
            });
            html += '</ul>';
            
            // Create tab panes
            html += '<div class="tab-content" id="weekdayTabContent">';
            selectedWeekdays.forEach((day, index) => {
                const dayLower = day.toLowerCase();
                const isActive = index === 0 ? 'show active' : '';
                html += `
                    <div class="tab-pane fade ${isActive}" id="pane-${dayLower}" role="tabpanel" aria-labelledby="tab-${dayLower}">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="mb-3"><strong>${day} Official Times</strong></h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Time In <span class="text-danger">*</span></label>
                                        <input type="time" id="time_in_${dayLower}" class="form-control" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Lunch Out</label>
                                        <input type="time" id="lunch_out_${dayLower}" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Lunch In</label>
                                        <input type="time" id="lunch_in_${dayLower}" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Time Out <span class="text-danger">*</span></label>
                                        <input type="time" id="time_out_${dayLower}" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
            
            // Re-attach change listeners if in edit mode
            if (window.editingOfficialTimeIds && Object.keys(window.editingOfficialTimeIds).length > 0) {
                // Re-capture original values if they exist (in case new inputs were created)
                setTimeout(() => {
                    window.attachChangeListeners();
                    window.updateSaveButtonText();
                }, 100);
            }
        };
        
        // Add event listeners to weekday checkboxes when modal opens
        document.addEventListener('DOMContentLoaded', function() {
            // Use event delegation for dynamically added checkboxes
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('weekday-checkbox')) {
                    window.updateWeekdayTimeInputs();
                }
            });
        });
        
        // Define saveOfficialTimes function inline to ensure it's always available
        window.saveOfficialTimes = function() {
            try {
                const startDate = document.getElementById('officialTimesStartDate').value;
                const endDate = document.getElementById('officialTimesEndDate').value;
                const employeeId = window.currentOfficialTimesEmpId;
                
                if (!startDate) {
                    alert('Please fill in Start Date');
                    return;
                }
                
                if (!employeeId) {
                    alert('Error: Safe Employee ID not set. Please close and reopen the modal.');
                    return;
                }
                
                // Validate date range
                if (endDate && endDate < startDate) {
                    alert('End date must be after start date');
                    return;
                }
                
                // Get selected weekdays
                const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                const selectedWeekdays = [];
                
                weekdays.forEach(day => {
                    const checkbox = document.getElementById('weekday-' + day.toLowerCase());
                    if (checkbox && checkbox.checked) {
                        selectedWeekdays.push(day);
                    }
                });
                
                if (selectedWeekdays.length === 0) {
                    alert('Please select at least one weekday');
                    return;
                }
                
                // Validate and collect times for each selected weekday
                const weekdayTimes = [];
                let hasError = false;
                
                selectedWeekdays.forEach(day => {
                    const dayLower = day.toLowerCase();
                    const timeIn = document.getElementById('time_in_' + dayLower)?.value;
                    const timeOut = document.getElementById('time_out_' + dayLower)?.value;
                    const lunchOut = document.getElementById('lunch_out_' + dayLower)?.value || '';
                    const lunchIn = document.getElementById('lunch_in_' + dayLower)?.value || '';
                    
                    if (!timeIn || !timeOut) {
                        alert(`Please fill in Time In and Time Out for ${day}`);
                        hasError = true;
                        return;
                    }
                    
                    weekdayTimes.push({
                        weekday: day,
                        time_in: timeIn,
                        lunch_out: lunchOut,
                        lunch_in: lunchIn,
                        time_out: timeOut
                    });
                });
                
                if (hasError) return;
                
                // Save each weekday entry
                let savePromises = [];
                weekdayTimes.forEach(wt => {
                    const formData = new FormData();
                    formData.append('action', 'save');
                    formData.append('employee_id', employeeId);
                    formData.append('start_date', startDate);
                    formData.append('end_date', endDate || '');
                    formData.append('weekday', wt.weekday);
                    formData.append('time_in', wt.time_in);
                    formData.append('lunch_out', wt.lunch_out);
                    formData.append('lunch_in', wt.lunch_in);
                    formData.append('time_out', wt.time_out);
                    
                    // If in edit mode and we have an ID for this weekday, include it for update
                    const editId = window.editingOfficialTimeIds && window.editingOfficialTimeIds[wt.weekday] ? window.editingOfficialTimeIds[wt.weekday] : null;
                    if (editId) {
                        formData.append('id', editId);
                        console.log(`Saving ${wt.weekday} with ID: ${editId} (edit mode)`);
                    } else {
                        console.log(`Saving ${wt.weekday} as new entry (no ID)`);
                    }
                    
                    savePromises.push(
                        fetch('manage_official_times_api.php', {
                            method: 'POST',
                            body: formData
                        }).then(response => response.json())
                    );
                });
                
                // Execute all save operations
                Promise.all(savePromises)
                .then(results => {
                    console.log('Save responses:', results);
                    const allSuccess = results.every(r => r.success);
                    const errorMessages = results.filter(r => !r.success).map(r => r.message).join(', ');
                    
                    if (allSuccess) {
                        // Exit edit mode after successful save
                        window.exitEditMode();
                        
                        // Clear the official times cache to force fresh data
                        if (typeof employeeOfficialTimesCache !== 'undefined') {
                            // Clear all cache entries for this employee
                            const employeeId = window.currentOfficialTimesEmpId;
                            if (employeeId) {
                                Object.keys(employeeOfficialTimesCache).forEach(key => {
                                    if (key.startsWith(employeeId + '_')) {
                                        delete employeeOfficialTimesCache[key];
                                    }
                                });
                                console.log('Cleared official times cache for employee:', employeeId);
                            }
                        }
                        
                        // Use showToast if available, otherwise use alert
                        const message = window.editingOfficialTimeIds && Object.keys(window.editingOfficialTimeIds).length > 0
                            ? `Official times updated successfully for ${selectedWeekdays.length} weekday(s)`
                            : `Official times saved successfully for ${selectedWeekdays.length} weekday(s)`;
                        
                        if (typeof showToast === 'function') {
                            showToast(message, 'success');
                        } else {
                            alert(message);
                        }
                        
                        // Reload logs to refresh status column
                        if (typeof loadLogsWithFilters === 'function' && window.currentLogsEmployeeId) {
                            loadLogsWithFilters(window.currentLogsEmployeeId);
                        }
                        
                        // Reload history - use direct API call to ensure it works
                        const employeeId = window.currentOfficialTimesEmpId;
                        console.log('Reloading history for employee:', employeeId);
                        if (employeeId) {
                            fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(employeeId)}`)
                                .then(response => {
                                    console.log('Response status:', response.status);
                                    if (!response.ok) {
                                        throw new Error('HTTP error! status: ' + response.status);
                                    }
                                    return response.text();
                                })
                                .then(text => {
                                    console.log('Raw response:', text);
                                    try {
                                        const historyData = JSON.parse(text);
                                        console.log('Parsed history data:', historyData);
                                        const tbody = document.getElementById('officialTimesHistoryBody');
                                        if (!tbody) {
                                            console.error('History table body not found');
                                            return;
                                        }
                                        
                                        tbody.innerHTML = '';
                                        
                                        if (historyData.success && historyData.official_times && historyData.official_times.length > 0) {
                                            console.log('Found', historyData.official_times.length, 'official times');
                                            historyData.official_times.forEach(ot => {
                                                console.log('Processing official time:', ot);
                                                const row = document.createElement('tr');
                                                row.innerHTML = `
                                                    <td>${ot.start_date || '-'}</td>
                                                    <td>${ot.end_date || '<span class="text-success">Ongoing</span>'}</td>
                                                    <td>${ot.weekday || '-'}</td>
                                                    <td>${ot.time_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_in) : ot.time_in) : '-'}</td>
                                                    <td>${ot.lunch_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_out) : ot.lunch_out) : '-'}</td>
                                                    <td>${ot.lunch_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_in) : ot.lunch_in) : '-'}</td>
                                                    <td>${ot.time_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_out) : ot.time_out) : '-'}</td>
                                                    <td>
                                                        <button onclick="window.loadOfficialTime('${ot.start_date}', '${ot.weekday || ''}')" class="btn btn-sm btn-primary" title="Edit">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button onclick="window.deleteOfficialTime(${ot.id})" class="btn btn-sm btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                `;
                                                tbody.appendChild(row);
                                            });
                                        } else {
                                            console.log('No official times found or success=false');
                                            console.log('Success:', historyData.success);
                                            console.log('Official times:', historyData.official_times);
                                            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No official times set yet</td></tr>';
                                        }
                                    } catch (e) {
                                        console.error('Error parsing JSON:', e);
                                        console.error('Response text:', text);
                                        const tbody = document.getElementById('officialTimesHistoryBody');
                                        if (tbody) {
                                            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Error parsing response: ' + e.message + '</td></tr>';
                                        }
                                    }
                                })
                                .catch(error => {
                                    console.error('Error loading history:', error);
                                    const tbody = document.getElementById('officialTimesHistoryBody');
                                    if (tbody) {
                                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Error loading history: ' + error.message + '</td></tr>';
                                    }
                                });
                        } else {
                            console.error('Employee ID not set');
                        }
                    } else {
                        alert('Some entries failed to save: ' + errorMessages);
                    }
                })
                .catch(error => {
                    console.error('Error saving official times:', error);
                    alert('Error saving official times: ' + error.message);
                });
            } catch (error) {
                console.error('Error in saveOfficialTimes:', error);
                alert('Error: ' + error.message);
            }
        };
        console.log('saveOfficialTimes defined inline');
        
        // Define manageOfficialTimes function inline to ensure it's always available
        window.manageOfficialTimes = function(empId, empName) {
            try {
                console.log('manageOfficialTimes called (inline version)');
                const modalElement = document.getElementById('employeeManagementModal');
                if (!modalElement) {
                    alert('Error: Modal not found. Please refresh the page.');
                    return;
                }
                
                // Store employee ID globally
                window.currentOfficialTimesEmpId = empId;
                
                // Exit edit mode when opening modal
                window.exitEditMode();
                
                // Update modal title and employee name
                const titleElement = document.getElementById('employeeManagementModalTitle');
                const nameElement = document.getElementById('employeeManagementEmployeeName');
                
                if (titleElement) titleElement.textContent = 'Employee Management - ' + empName;
                if (nameElement) nameElement.textContent = 'Safe Employee ID: ' + empId;
                
                // Show TIME tab and hide DTR tab
                const dtrTab = document.getElementById('dtr-tab');
                const timeTab = document.getElementById('time-tab');
                const dtrPane = document.getElementById('dtr-pane');
                const timePane = document.getElementById('time-pane');
                const dtrFooter = document.getElementById('dtr-footer-buttons');
                const timeFooter = document.getElementById('time-footer-buttons');
                
                if (dtrTab && timeTab && dtrPane && timePane) {
                    // Activate TIME tab
                    timeTab.classList.add('active');
                    dtrTab.classList.remove('active');
                    timePane.classList.add('show', 'active');
                    dtrPane.classList.remove('show', 'active');
                }
                
                // Show TIME footer buttons, hide DTR footer buttons
                if (timeFooter) timeFooter.style.display = '';
                if (dtrFooter) dtrFooter.style.display = 'none';
                
                // Set default start date to today
                const startDateElement = document.getElementById('officialTimesStartDate');
                const endDateElement = document.getElementById('officialTimesEndDate');
                
                if (startDateElement) {
                    const today = new Date();
                    startDateElement.value = today.toISOString().split('T')[0];
                }
                
                if (endDateElement) {
                    endDateElement.value = '';
                }
                
                // Uncheck all weekday checkboxes
                const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                weekdays.forEach(day => {
                    const checkbox = document.getElementById('weekday-' + day.toLowerCase());
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
                
                // Clear weekday inputs
                if (typeof window.updateWeekdayTimeInputs === 'function') {
                    window.updateWeekdayTimeInputs();
                }
                
                // Load data if functions are available
                setTimeout(() => {
                    // Load history first
                    const empId = window.currentOfficialTimesEmpId;
                    console.log('Loading history for employee:', empId);
                    if (empId) {
                        fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(empId)}`)
                            .then(response => {
                                console.log('Initial history response status:', response.status);
                                return response.text();
                            })
                            .then(text => {
                                console.log('Initial history raw response:', text);
                                try {
                                    const historyData = JSON.parse(text);
                                    console.log('Initial history parsed data:', historyData);
                                    const tbody = document.getElementById('officialTimesHistoryBody');
                                    if (tbody) {
                                        tbody.innerHTML = '';
                                        if (historyData.success && historyData.official_times && historyData.official_times.length > 0) {
                                            console.log('Found', historyData.official_times.length, 'official times on modal open');
                                            historyData.official_times.forEach(ot => {
                                                const row = document.createElement('tr');
                                                row.innerHTML = `
                                                    <td>${ot.start_date || '-'}</td>
                                                    <td>${ot.end_date || '<span class="text-success">Ongoing</span>'}</td>
                                                    <td>${ot.weekday || '-'}</td>
                                                    <td>${ot.time_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_in) : ot.time_in) : '-'}</td>
                                                    <td>${ot.lunch_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_out) : ot.lunch_out) : '-'}</td>
                                                    <td>${ot.lunch_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_in) : ot.lunch_in) : '-'}</td>
                                                    <td>${ot.time_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_out) : ot.time_out) : '-'}</td>
                                                    <td>
                                                        <button onclick="window.loadOfficialTime('${ot.start_date}', '${ot.weekday || ''}')" class="btn btn-sm btn-primary" title="Edit">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button onclick="window.deleteOfficialTime(${ot.id})" class="btn btn-sm btn-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                `;
                                                tbody.appendChild(row);
                                            });
                                        } else {
                                            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No official times set yet</td></tr>';
                                        }
                                    }
                                } catch (e) {
                                    console.error('Error parsing initial history JSON:', e);
                                }
                            })
                            .catch(error => {
                                console.error('Error loading initial history:', error);
                            });
                    }
                    
                    // Update weekday inputs if any are already selected
                    if (typeof window.updateWeekdayTimeInputs === 'function') {
                        window.updateWeekdayTimeInputs();
                    }
                }, 100);
                
                // Add change listeners to weekday checkboxes
                setTimeout(() => {
                    const checkboxes = document.querySelectorAll('.weekday-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.addEventListener('change', function() {
                            if (typeof window.updateWeekdayTimeInputs === 'function') {
                                window.updateWeekdayTimeInputs();
                            }
                        });
                    });
                }, 200);
                
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            } catch (error) {
                console.error('Error in manageOfficialTimes:', error);
                alert('Error: ' + error.message);
            }
        };
        console.log('manageOfficialTimes defined inline');
        
        // Handle tab switching to show/hide appropriate footer buttons
        document.addEventListener('DOMContentLoaded', function() {
            const dtrTab = document.getElementById('dtr-tab');
            const timeTab = document.getElementById('time-tab');
            const dtrFooter = document.getElementById('dtr-footer-buttons');
            const timeFooter = document.getElementById('time-footer-buttons');
            
            if (dtrTab && timeTab) {
                dtrTab.addEventListener('shown.bs.tab', function() {
                    if (dtrFooter) dtrFooter.style.display = '';
                    if (timeFooter) timeFooter.style.display = 'none';
                });
                
                timeTab.addEventListener('shown.bs.tab', function() {
                    if (timeFooter) timeFooter.style.display = '';
                    if (dtrFooter) dtrFooter.style.display = 'none';
                    
                    // Load official times history when switching to TIME tab
                    const empId = window.currentOfficialTimesEmpId || window.currentLogsEmployeeId;
                    if (empId) {
                        // Update the employee ID if it was set via viewLogs
                        window.currentOfficialTimesEmpId = empId;
                        
                        // Load history
                        fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(empId)}`)
                            .then(response => response.json())
                            .then(historyData => {
                                const tbody = document.getElementById('officialTimesHistoryBody');
                                if (tbody) {
                                    tbody.innerHTML = '';
                                    if (historyData.success && historyData.official_times && historyData.official_times.length > 0) {
                                        historyData.official_times.forEach(ot => {
                                            const row = document.createElement('tr');
                                            row.innerHTML = `
                                                <td>${ot.start_date || '-'}</td>
                                                <td>${ot.end_date || '<span class="text-success">Ongoing</span>'}</td>
                                                <td>${ot.weekday || '-'}</td>
                                                <td>${ot.time_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_in) : ot.time_in) : '-'}</td>
                                                <td>${ot.lunch_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_out) : ot.lunch_out) : '-'}</td>
                                                <td>${ot.lunch_in ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.lunch_in) : ot.lunch_in) : '-'}</td>
                                                <td>${ot.time_out ? (window.formatTimeTo12h ? window.formatTimeTo12h(ot.time_out) : ot.time_out) : '-'}</td>
                                                <td>
                                                    <button onclick="window.loadOfficialTime('${ot.start_date}', '${ot.weekday || ''}')" class="btn btn-sm btn-primary" title="Edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button onclick="window.deleteOfficialTime(${ot.id})" class="btn btn-sm btn-danger" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            `;
                                            tbody.appendChild(row);
                                        });
                                    } else {
                                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No official times set yet</td></tr>';
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Error loading official times history:', error);
                            });
                    }
                });
            }
        });
    </script>
    <?php 
    // Add cache-busting based on file modification time
    $jsPath = __DIR__ . '/../assets/js/employee_logs.js';
    $jsVersion = file_exists($jsPath) ? filemtime($jsPath) : time();
    ?>
    <script>window.isSuperAdmin = <?php echo isSuperAdmin() ? 'true' : 'false'; ?>;</script>
    <script src="<?php echo asset_url('js/employee_logs.js'); ?>?v=<?php echo $jsVersion; ?>"></script>
    <script>
        // Verify the function loaded properly and ensure everything is ready
        (function() {
            function verifyAndInit() {
                // Check if external script loaded and overrode inline version
                if (typeof window.openAllTardinessModal === 'function') {
                    const funcStr = window.openAllTardinessModal.toString();
                    // If it's the inline version, that's fine - it works
                    // If external script loaded, it should have overridden it
                    if (funcStr.includes('inline version')) {
                        console.log('ℹ openAllTardinessModal using inline version (external script may override)');
                    } else if (!funcStr.includes('still loading') && !funcStr.includes('waiting for external script') && !funcStr.includes('failed to load')) {
                        console.log('✓ openAllTardinessModal loaded from external script');
                    }
                } else {
                    console.error('✗ openAllTardinessModal not defined after script load');
                }
                
                // Verify loadAllTardiness is available
                if (typeof window.loadAllTardiness === 'function') {
                    console.log('✓ loadAllTardiness is available');
                } else {
                    console.warn('⚠ loadAllTardiness not yet available (may load shortly)');
                }
            }
            
            // Run immediately
            verifyAndInit();
            
            // Also run after a delay to catch late-loading scripts
            setTimeout(verifyAndInit, 500);
            setTimeout(verifyAndInit, 1000);
        })();
    </script>
    <script>
        // Wait for script to load, then initialize handlers
        function initTardinessModalHandler() {
            // Check if function exists
            if (typeof window.openAllTardinessModal !== 'function') {
                console.warn('openAllTardinessModal not a function yet');
                return false;
            }
            
            // Check if it's the error fallback (but NOT the inline working version)
            const funcStr = window.openAllTardinessModal.toString();
            // The inline version contains "inline version" - that's valid
            // Only reject if it's the minimal error fallback
            const isErrorFallback = funcStr.includes('failed to load') && !funcStr.includes('inline version');
            const isWaitingFallback = funcStr.includes('still loading') || funcStr.includes('waiting for external script');
            
            if (isErrorFallback || isWaitingFallback) {
                console.warn('openAllTardinessModal still using error fallback - script may not have loaded');
                return false; // Indicate failure
            }
            
            // Find all buttons with openAllTardinessModal in onclick
            const tardinessButtons = document.querySelectorAll('button[onclick*="openAllTardinessModal"]');
            let buttonsProcessed = 0;
            
            tardinessButtons.forEach(function(btn) {
                // Skip if already processed
                if (btn.hasAttribute('data-tardiness-handler-bound')) {
                    return;
                }
                
                // Mark as processed
                btn.setAttribute('data-tardiness-handler-bound', 'true');
                
                // Remove inline onclick to avoid conflicts
                const originalOnclick = btn.getAttribute('onclick');
                if (originalOnclick) {
                    btn.removeAttribute('onclick');
                    
                    // Add event listener
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Tardiness button clicked');
                        
                        // Verify function is available and not error fallback
                        if (typeof window.openAllTardinessModal === 'function') {
                            const funcStr2 = window.openAllTardinessModal.toString();
                            // Allow inline version (contains "inline version") but reject error fallbacks
                            const isErrorFallback = funcStr2.includes('failed to load') && !funcStr2.includes('inline version');
                            const isWaitingFallback = funcStr2.includes('still loading') || funcStr2.includes('waiting for external script');
                            
                            if (isErrorFallback || isWaitingFallback) {
                                console.error('openAllTardinessModal still using error fallback');
                                alert('Error: Modal script not loaded. Please refresh the page.');
                                return false;
                            }
                            
                            try {
                                window.openAllTardinessModal();
                            } catch (error) {
                                console.error('Error calling openAllTardinessModal:', error);
                                alert('Error opening modal: ' + error.message);
                            }
                        } else {
                            console.error('openAllTardinessModal function not found');
                            alert('Error: Modal function not loaded. Please refresh the page.');
                        }
                        return false;
                    }, { once: false });
                    
                    buttonsProcessed++;
                }
            });
            
            if (buttonsProcessed > 0) {
                console.log('Initialized', buttonsProcessed, 'tardiness modal button(s)');
            }
            
            // Return true if function is available and not a fallback
            if (typeof window.openAllTardinessModal === 'function') {
                const funcStr = window.openAllTardinessModal.toString();
                const isErrorFallback = funcStr.includes('failed to load') && !funcStr.includes('inline version');
                const isWaitingFallback = funcStr.includes('still loading') || funcStr.includes('waiting for external script');
                return !(isErrorFallback || isWaitingFallback);
            }
            return false;
        }
        
        // Initialize handler - works on both initial load and navigation
        function ensureInitialized() {
            // Try immediately
            if (initTardinessModalHandler()) {
                return; // Success
            }
            
            // Retry with increasing delays
            let attempts = 0;
            const maxAttempts = 10;
            
            function retry() {
                attempts++;
                if (attempts > maxAttempts) {
                    console.warn('Max initialization attempts reached for tardiness modal');
                    return;
                }
                
                if (initTardinessModalHandler()) {
                    console.log('Tardiness modal handler initialized on attempt', attempts);
                    return; // Success
                }
                
                // Retry with exponential backoff
                setTimeout(retry, Math.min(100 * attempts, 1000));
            }
            
            // Start retry chain
            setTimeout(retry, 100);
        }
        
        // Run on DOM ready or immediately if already ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', ensureInitialized);
        } else {
            // DOM already loaded (e.g., from navigation)
            ensureInitialized();
        }
        
        // Also try on window load (for navigation scenarios)
        window.addEventListener('load', function() {
            setTimeout(ensureInitialized, 100);
        });
        
        // Re-initialize when page becomes visible (handles back/forward navigation)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // Page became visible - re-initialize
                setTimeout(ensureInitialized, 100);
            }
        });
        
        // Re-initialize on pageshow (handles browser back/forward cache)
        window.addEventListener('pageshow', function(event) {
            // event.persisted is true if page was loaded from cache
            if (event.persisted) {
                console.log('Page loaded from cache - re-initializing...');
                setTimeout(ensureInitialized, 100);
            }
        });
    </script>
    <script>
        // Suppress 403/401 errors from notifications and chat APIs in console (run early, before other scripts)
        (function() {
            const originalError = console.error;
            const originalWarn = console.warn;
            
            console.error = function(...args) {
                const message = args.join(' ').toLowerCase();
                // Suppress 403/401 errors from API calls
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized') ||
                    (message.includes('fetch failed') && (message.includes('notifications_api') || message.includes('chat_api'))) ||
                    (message.includes('error loading conversations') && (message.includes('403') || message.includes('forbidden'))) ||
                    (message.includes('error checking notifications') && (message.includes('403') || message.includes('forbidden'))) ||
                    (message.includes('network response was not ok') && (message.includes('403') || message.includes('forbidden')))) {
                    return; // Don't log these errors
                }
                originalError.apply(console, args);
            };
            
            // Also suppress warnings for 403 errors
            console.warn = function(...args) {
                const message = args.join(' ').toLowerCase();
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized')) {
                    return; // Don't log these warnings
                }
                originalWarn.apply(console, args);
            };
        })();
        
        // Ensure saveOfficialTimes is accessible
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                if (typeof window.saveOfficialTimes === 'undefined') {
                    console.error('saveOfficialTimes not loaded from external script');
                } else {
                    console.log('saveOfficialTimes is available:', typeof window.saveOfficialTimes);
                }
            }, 500);
        });
    </script>
</body>
</html>
            
            