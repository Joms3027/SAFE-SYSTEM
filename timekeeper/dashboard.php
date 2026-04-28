<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireTimekeeper();

// Check if user is coming from QR scanner - if so, require password verification
// Check both session flag and referer for reliability
$isFromQRScanner = isset($_SESSION['from_qr_scanner']) && $_SESSION['from_qr_scanner'] === true;
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!$isFromQRScanner) {
    $isFromQRScanner = strpos($referer, 'qrcode-scanner.php') !== false;
}

// If coming from QR scanner and password not verified, require verification
if ($isFromQRScanner && (!isset($_SESSION['timekeeper_password_verified']) || $_SESSION['timekeeper_password_verified'] !== true)) {
    // Store the intended destination
    $_SESSION['timekeeper_redirect_after_auth'] = $_SERVER['REQUEST_URI'];
    redirect('verify-password.php');
}

// Clear the QR scanner flag after checking (so it doesn't persist)
unset($_SESSION['from_qr_scanner']);

$database = Database::getInstance();
$db = $database->getConnection();

// Get timekeeper/station details
$timekeeper = null;

if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station') {
    // Station-based login (new method)
    $timekeeper = [
        'station_name' => $_SESSION['station_name'] ?? 'Unknown Station',
        'station_id' => $_SESSION['station_id'] ?? 0
    ];
} elseif (isset($_SESSION['timekeeper_id'])) {
    // Old timekeeper login with user account
    $stmt = $db->prepare("
        SELECT tk.*, u.first_name, u.last_name, u.email,
               fp.employee_id, s.name as station_name, s.id as station_id
        FROM timekeepers tk
        INNER JOIN users u ON tk.user_id = u.id
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
        LEFT JOIN stations s ON tk.station_id = s.id
        WHERE tk.id = ?
    ");
    $stmt->execute([$_SESSION['timekeeper_id']]);
    $timekeeper = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timekeeper Dashboard - WPU Faculty System</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/timekeeper-mobile.css?v=2.1.0" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        /* Scan QR Code button width - desktop */
        #scanQRCodeBtn {
            width: 26% !important;
        }
        
        /* Add Employee button width - desktop */
        #addEmployeeBtn {
            width: 26% !important;
        }
        
        /* Override width on mobile screens for Scan QR Code button */
        @media (max-width: 767.98px) {
            #scanQRCodeBtn {
                width: auto !important;
            }
            
            /* Override width on mobile screens for Add Employee button */
            #addEmployeeBtn {
                width: auto !important;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-3 mt-md-5">
        <div class="row">
            <div class="col-md-12">
                <div class="mb-3 mb-md-4">
                    <div class="d-flex justify-content-between align-items-center flex-column flex-md-row gap-3">
                        <h1 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
                        <a href="../logout.php" class="btn btn-outline-danger w-100 w-md-auto" style="width: 26% !important;">
                            <i class="bi bi-box-arrow-right me-2" ></i>Logout
                        </a>
                    </div>
                </div>

                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-4" id="dashboardTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab" aria-controls="attendance" aria-selected="true">
                            <i class="bi bi-calendar-check me-2"></i>Attendance
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="employees-tab" data-bs-toggle="tab" data-bs-target="#employees" type="button" role="tab" aria-controls="employees" aria-selected="false">
                            <i class="bi bi-people me-2"></i>Manage Employees
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="leave-tab" data-bs-toggle="tab" data-bs-target="#leave" type="button" role="tab" aria-controls="leave" aria-selected="false">
                            <i class="bi bi-calendar-x me-2"></i>Leave
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tardiness-tab" data-bs-toggle="tab" data-bs-target="#tardiness" type="button" role="tab" aria-controls="tardiness" aria-selected="false">
                            <i class="bi bi-clock-history me-2"></i>Tardiness
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="absent-tab" data-bs-toggle="tab" data-bs-target="#absent" type="button" role="tab" aria-controls="absent" aria-selected="false">
                            <i class="bi bi-person-x me-2"></i>Absent
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content" id="dashboardTabsContent">
                    <!-- Attendance Tab -->
                    <div class="tab-pane fade show active" id="attendance" role="tabpanel" aria-labelledby="attendance-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance Management</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3 flex-column flex-md-row gap-3">
                                    <p class="mb-0 text-center text-md-start">View and manage employee attendance records.</p>
                                    <a href="qrcode-scanner.php" id="scanQRCodeBtn" class="btn btn-primary w-100 w-md-auto">
                                        <i class="bi bi-qr-code-scan me-2"></i>Scan QR Code
                                    </a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Date</th>
                                                <th>Safe Employee ID</th>
                                                <th>Name</th>
                                                <th>Time In</th>
                                                <th>Lunch Out</th>
                                                <th>Lunch In</th>
                                                <th>Time Out</th>
                                                <th>Total Hours</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="attendanceTableBody">
                                            <tr>
                                                <td colspan="9" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox me-2"></i>No attendance records found
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manage Employees Tab -->
                    <div class="tab-pane fade" id="employees" role="tabpanel" aria-labelledby="employees-tab">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center flex-column flex-md-row gap-3">
                                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>Manage Employees</h5>
                                    <button id="addEmployeeBtn" class="btn btn-sm btn-primary w-100 w-md-auto" onclick="showAddEmployeeModal()">
                                        <i class="bi bi-person-plus me-2"></i>Add Employee
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <p>Manage employee information, add new employees, edit existing records, and view employee details.</p>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Safe Employee ID</th>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Department</th>
                                                <th>Position</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="employeesTableBody">
                                            <tr>
                                                <td colspan="7" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox me-2"></i>No employees found
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Leave Tab -->
                    <div class="tab-pane fade" id="leave" role="tabpanel" aria-labelledby="leave-tab">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center flex-column flex-md-row gap-3">
                                    <h5 class="mb-0"><i class="bi bi-calendar-x me-2"></i>Leave Management</h5>
                                    <button class="btn btn-sm btn-primary w-100 w-md-auto" onclick="showAddLeaveModal()">
                                        <i class="bi bi-plus-circle me-2"></i>Request Leave
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <p>View and manage employee leave requests, approve or reject leave applications.</p>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Safe Employee ID</th>
                                                <th>Name</th>
                                                <th>Leave Type</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Days</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="leaveTableBody">
                                            <tr>
                                                <td colspan="8" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox me-2"></i>No leave records found
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tardiness Tab -->
                    <div class="tab-pane fade" id="tardiness" role="tabpanel" aria-labelledby="tardiness-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Tardiness Records</h5>
                            </div>
                            <div class="card-body">
                                <p>View employee tardiness records and late arrivals.</p>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Date</th>
                                                <th>Safe Employee ID</th>
                                                <th>Name</th>
                                                <th>Scheduled Time</th>
                                                <th>Actual Time In</th>
                                                <th>Minutes Late</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tardinessTableBody">
                                            <tr>
                                                <td colspan="7" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox me-2"></i>No tardiness records found
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Absent Tab -->
                    <div class="tab-pane fade" id="absent" role="tabpanel" aria-labelledby="absent-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-person-x me-2"></i>Absent Records</h5>
                            </div>
                            <div class="card-body">
                                <p>View employee absence records and track unexcused absences.</p>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Safe Employee ID</th>
                                                <th>Name</th>
                                                <th>Total Absent Days</th>
                                                <th>Current Period</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="absentTableBody">
                                            <tr>
                                                <td colspan="5" class="text-center py-5 text-muted">
                                                    <i class="bi bi-inbox me-2"></i>No absent records found
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Add/Edit Employee Modal -->
    <div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="employeeModalLabel">Add Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="employeeForm">
                        <input type="hidden" id="employee_user_id" name="user_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="employee_first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="employee_last_name" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="employee_middle_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="employee_email" required>
                            </div>
                        </div>
                        <div class="row" id="passwordRow">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="employee_password" required autocomplete="current-password">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">User Type</label>
                                <select class="form-select" id="employee_user_type">
                                    <option value="staff">Staff</option>
                                    <option value="faculty">Faculty</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Safe Employee ID</label>
                                <input type="text" class="form-control" id="employee_employee_id">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" id="employee_department">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Position</label>
                                <input type="text" class="form-control" id="employee_position">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employment Status</label>
                                <select class="form-select" id="employee_employment_status">
                                    <option value="">Select Status</option>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Adjunct">Adjunct</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" id="employee_phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="employee_is_active">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" id="employee_address" rows="2"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveEmployee()">Save Employee</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Employee Modal -->
    <div class="modal fade" id="viewEmployeeModal" tabindex="-1" aria-labelledby="viewEmployeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewEmployeeModalLabel">Employee Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewEmployeeContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Attendance Details Modal -->
    <div class="modal fade" id="viewAttendanceModal" tabindex="-1" aria-labelledby="viewAttendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewAttendanceModalLabel">Attendance History & Analytics</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewAttendanceContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Leave Request Modal -->
    <div class="modal fade" id="leaveModal" tabindex="-1" aria-labelledby="leaveModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="leaveModalLabel">Request Leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="leaveForm">
                        <div class="mb-3">
                            <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="leave_type" required>
                                <option value="">Select Leave Type</option>
                                <option value="Vacation">Vacation</option>
                                <option value="Sick Leave">Sick Leave</option>
                                <option value="Personal">Personal</option>
                                <option value="Emergency">Emergency</option>
                                <option value="Maternity">Maternity</option>
                                <option value="Paternity">Paternity</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="leave_start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="leave_end_date" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason</label>
                            <textarea class="form-control" id="leave_reason" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveLeave()">Submit Request</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Leave Details Modal -->
    <div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-labelledby="viewLeaveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewLeaveModalLabel">Leave Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewLeaveContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Tardiness Details Modal -->
    <div class="modal fade" id="viewTardinessModal" tabindex="-1" aria-labelledby="viewTardinessModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewTardinessModalLabel">Tardiness Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewTardinessContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Absent Details Modal -->
    <div class="modal fade" id="viewAbsentModal" tabindex="-1" aria-labelledby="viewAbsentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewAbsentModalLabel">Absent History & Analytics</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewAbsentContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="js/dashboard.js"></script>
</body>
</html>
