<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Get key statistics first
$stats = getStats();

// Global report date range (from GET or default)
$defaultEnd = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-30 days'));
$reportStartDate = !empty($_GET['report_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['report_start'])
    ? $_GET['report_start'] : $defaultStart;
$reportEndDate = !empty($_GET['report_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['report_end'])
    ? $_GET['report_end'] : $defaultEnd;
if ($reportStartDate > $reportEndDate) {
    $reportStartDate = $reportEndDate;
}
$reportStartFormatted = date('M j, Y', strtotime($reportStartDate));
$reportEndFormatted = date('M j, Y', strtotime($reportEndDate));

// Get analytics data (all filtered by report date range)

// Submission trends (within date range)
$stmt = $db->prepare("
    SELECT DATE(submitted_at) as date, COUNT(*) as count
    FROM faculty_submissions
    WHERE DATE(submitted_at) >= ? AND DATE(submitted_at) <= ?
    GROUP BY DATE(submitted_at)
    ORDER BY date ASC
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$submissionTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Submission status breakdown (within date range)
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count
    FROM faculty_submissions
    WHERE DATE(submitted_at) >= ? AND DATE(submitted_at) <= ?
    GROUP BY status
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$submissionStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Department distribution
$stmt = $db->prepare("
    SELECT 
        COALESCE(fp.department, 'Unspecified') as department, 
        COUNT(*) as count
    FROM users u
    LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
    WHERE u.user_type = 'faculty' AND u.is_verified = 1
    GROUP BY fp.department
");
$stmt->execute();
$departmentDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Employees by department (for clickable chart modal)
$stmt = $db->prepare("
    SELECT 
        COALESCE(fp.department, 'Unspecified') as department,
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        fp.employee_id,
        fp.position
    FROM users u
    LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
    WHERE u.user_type = 'faculty' AND u.is_verified = 1
    ORDER BY COALESCE(fp.department, 'Unspecified'), u.last_name, u.first_name
");
$stmt->execute();
$employeesByDepartment = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dept = $row['department'];
    if (!isset($employeesByDepartment[$dept])) {
        $employeesByDepartment[$dept] = [];
    }
    $employeesByDepartment[$dept][] = [
        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        'email' => $row['email'],
        'employee_id' => $row['employee_id'] ?? '-',
        'position' => $row['position'] ?? '-'
    ];
}

// PDS status breakdown
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count
    FROM faculty_pds
    GROUP BY status
");
$stmt->execute();
$pdsStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly faculty registrations (within date range)
$stmt = $db->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
    FROM users
    WHERE user_type = 'faculty'
    AND DATE(created_at) >= ? AND DATE(created_at) <= ?
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$monthlyRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Deadline compliance (optimized: calculate total_faculty once, avoid subquery in SELECT)
$totalFacultyStmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE user_type = 'faculty' AND is_verified = 1");
$totalFacultyStmt->execute();
$totalFaculty = $totalFacultyStmt->fetchColumn() ?: 1; // Avoid division by zero

$stmt = $db->prepare("
    SELECT 
        r.id,
        r.title as requirement,
        r.deadline,
        COUNT(DISTINCT fs.faculty_id) as submitted_count
    FROM requirements r
    LEFT JOIN faculty_submissions fs ON r.id = fs.requirement_id
    WHERE r.is_active = 1
    GROUP BY r.id, r.title, r.deadline
    ORDER BY r.deadline DESC
    LIMIT 10
");
$stmt->execute();
$deadlineCompliance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate compliance rate in PHP to avoid subquery
foreach ($deadlineCompliance as &$req) {
    $req['total_faculty'] = $totalFaculty;
    $req['compliance_rate'] = round(($req['submitted_count'] * 100.0 / $totalFaculty), 2);
}
unset($req);

// Recent activity summary (within date range)
$stmt = $db->prepare("
    SELECT 
        action,
        COUNT(*) as count
    FROM system_logs
    WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
    GROUP BY action
    ORDER BY count DESC
    LIMIT 10
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Announcement statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_announcements,
        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_announcements,
        COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_announcements
    FROM announcements
");
$stmt->execute();
$announcementStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Notification statistics (within date range)
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_notifications,
        COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread_notifications,
        COUNT(CASE WHEN type = 'announcement' THEN 1 END) as announcement_notifications,
        COUNT(CASE WHEN type = 'new_requirement' THEN 1 END) as requirement_notifications
    FROM notifications
    WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$notificationStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Requirement assignment analytics
$stmt = $db->prepare("
    SELECT 
        r.title,
        COUNT(DISTINCT fr.faculty_id) as assigned_count,
        COUNT(DISTINCT fs.faculty_id) as submitted_count
    FROM requirements r
    LEFT JOIN faculty_requirements fr ON r.id = fr.requirement_id
    LEFT JOIN faculty_submissions fs ON r.id = fs.requirement_id AND fs.status = 'approved'
    WHERE r.is_active = 1
    GROUP BY r.id
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute();
$requirementAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active users (uses global report date range): distinct users who logged in during the period
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT user_id) as count
    FROM security_logs
    WHERE event_type = 'LOGIN_SUCCESS'
    AND user_id IS NOT NULL
    AND DATE(created_at) >= ?
    AND DATE(created_at) <= ?
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$activeUsersCount = (int) $stmt->fetchColumn();

// Pardon requests: distinct users/employees who submitted pardon requests during the period
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT employee_id) as count
    FROM pardon_requests
    WHERE DATE(created_at) >= ?
    AND DATE(created_at) <= ?
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$pardonRequestUsersCount = (int) $stmt->fetchColumn();

// Late time-in: distinct employees with time_in later than their official schedule
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT al.employee_id) as count
    FROM attendance_logs al
    INNER JOIN employee_official_times eot ON al.employee_id = eot.employee_id
        AND DAYNAME(al.log_date) = eot.weekday
        AND al.log_date >= eot.start_date
        AND (eot.end_date IS NULL OR al.log_date <= eot.end_date)
    WHERE al.log_date >= ?
    AND al.log_date <= ?
    AND al.time_in IS NOT NULL
    AND al.time_in > eot.time_in
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$lateTimeInUsersCount = (int) $stmt->fetchColumn();

// Fetch user lists for modals
// Active users list
$stmt = $db->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.user_type
    FROM users u
    INNER JOIN security_logs sl ON u.id = sl.user_id
    WHERE sl.event_type = 'LOGIN_SUCCESS'
    AND sl.user_id IS NOT NULL
    AND DATE(sl.created_at) >= ?
    AND DATE(sl.created_at) <= ?
    ORDER BY u.last_name, u.first_name
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$activeUsersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pardon request users list (distinct by employee_id)
$stmt = $db->prepare("
    SELECT employee_id,
        MAX(employee_first_name) as employee_first_name,
        MAX(employee_last_name) as employee_last_name,
        MAX(employee_department) as employee_department
    FROM pardon_requests
    WHERE DATE(created_at) >= ?
    AND DATE(created_at) <= ?
    GROUP BY employee_id
    ORDER BY employee_last_name, employee_first_name
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$pardonRequestUsersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Late time-in users list
$stmt = $db->prepare("
    SELECT DISTINCT al.employee_id,
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), al.employee_id) as full_name,
        fp.department
    FROM attendance_logs al
    INNER JOIN employee_official_times eot ON al.employee_id = eot.employee_id
        AND DAYNAME(al.log_date) = eot.weekday
        AND al.log_date >= eot.start_date
        AND (eot.end_date IS NULL OR al.log_date <= eot.end_date)
    LEFT JOIN faculty_profiles fp ON al.employee_id = fp.employee_id
    LEFT JOIN users u ON fp.user_id = u.id
    WHERE al.log_date >= ?
    AND al.log_date <= ?
    AND al.time_in IS NOT NULL
    AND al.time_in > eot.time_in
    ORDER BY full_name
");
$stmt->execute([$reportStartDate, $reportEndDate]);
$lateTimeInUsersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Analytics', 'View system analytics and statistics');
    ?>
    <script src="<?php echo asset_url('vendor/chartjs/chart.min.js', true); ?>"></script>
    <style>
        .chart-container {
            position: relative;
            height: 250px;
            margin-top: 1rem;
        }
        
        .analytics-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
            height: 100%;
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.2s ease;
        }
        
        
        .analytics-card h6 {
            color: var(--text-dark);
            margin-bottom: var(--spacing-lg);
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }
        
        .analytics-card h6 i {
            color: var(--primary-blue);
        }
        
        .metric-box {
            text-align: center;
            padding: var(--spacing-lg);
            background: linear-gradient(135deg, var(--office-gray) 0%, var(--light-gray) 100%);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
        }
        
        .metric-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-blue);
            line-height: 1;
        }
        
        .metric-label {
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-top: var(--spacing-xs);
        }
        
        .user-analytics-card {
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .user-analytics-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }
        
        .compliance-bar {
            height: 24px;
            background: var(--light-gray);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            border: 1px solid var(--border-light);
        }
        
        .compliance-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-blue), var(--secondary-blue));
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8px;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .stat-mini {
            text-align: center;
            padding: var(--spacing-md);
        }
        
        .stat-mini-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .stat-mini-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: var(--spacing-xs);
        }
        
        .section-header {
            color: var(--text-dark);
            font-size: 1.1rem;
            font-weight: 600;
            margin: var(--spacing-2xl) 0 var(--spacing-lg) 0;
            padding: var(--spacing-md) var(--spacing-lg);
            background: linear-gradient(135deg, var(--office-gray) 0%, var(--light-gray) 100%);
            border-left: 4px solid var(--primary-blue);
            border-radius: var(--radius-md);
        }
        
        .section-header i {
            color: var(--primary-blue);
        }
        
        .compact-table {
            font-size: 0.85rem;
        }
        
        .compact-table td, .compact-table th {
            padding: var(--spacing-md);
        }
        
        @media print {
            .no-print { display: none; }
            
            body {
                background: white !important;
                font-family: 'Times New Roman', Times, serif;
                color: #000;
                line-height: 1.6;
            }
            
            .container-fluid {
                max-width: 100%;
                padding: 0;
            }
            
            .main-content {
                padding: 0 !important;
            }
            
            .page-header {
                background: white !important;
                color: #000 !important;
                border: 3px solid #000;
                border-radius: 0 !important;
                padding: 20px !important;
                margin-bottom: 30px !important;
                text-align: center;
            }
            
            .page-header h1 {
                font-size: 24pt !important;
                font-weight: bold;
                margin: 0 0 10px 0 !important;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .page-header p {
                font-size: 12pt !important;
                font-style: italic;
                color: #333 !important;
            }
            
            .page-header i,
            .page-header .breadcrumb {
                display: none !important;
            }
            
            .page-header .page-title {
                font-size: 18pt !important;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .analytics-card {
                border: 1px solid #000 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                page-break-inside: avoid;
                margin-bottom: 20px !important;
                background: white !important;
            }
            
            .analytics-card h6 {
                background: #f0f0f0;
                color: #000 !important;
                padding: 8px 12px;
                margin: -1.25rem -1.25rem 1rem -1.25rem;
                border-bottom: 2px solid #000;
                font-size: 11pt !important;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .section-header {
                background: #000;
                color: white !important;
                padding: 8px 12px;
                margin: 30px 0 15px 0 !important;
                border: none !important;
                font-size: 13pt !important;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .metric-box {
                background: white !important;
                border: 1px solid #000;
                border-radius: 0 !important;
            }
            
            .metric-value {
                color: #000 !important;
                font-family: 'Times New Roman', Times, serif;
            }
            
            .metric-label {
                color: #333 !important;
                font-weight: 600;
            }
            
            .chart-container {
                border: 1px solid #ddd;
                padding: 10px;
                background: white;
            }
            
            .compliance-bar {
                border: 1px solid #000;
                border-radius: 0 !important;
                background: white !important;
            }
            
            .compliance-fill {
                background: #000 !important;
                color: white !important;
            }
            
            .stat-mini {
                border: 1px solid #ddd;
                background: white;
            }
            
            .stat-mini-value {
                color: #000 !important;
            }
            
            .stat-mini-label {
                color: #333 !important;
                font-weight: 600;
            }
            
            .badge {
                border: 1px solid #000 !important;
                color: #000 !important;
                background: white !important;
                font-weight: bold;
            }
            
            .text-success, .text-warning, .text-danger, .text-info {
                color: #000 !important;
            }
            
            .text-muted {
                color: #666 !important;
            }
            
            /* Formal document structure */
            @page {
                margin: 1.5cm;
                @top-center {
                    content: "WPU FACULTY SYSTEM - ANALYTICS REPORT (<?php echo htmlspecialchars($reportStartFormatted); ?> to <?php echo htmlspecialchars($reportEndFormatted); ?>)";
                    font-size: 9pt;
                    font-weight: bold;
                }
                @bottom-center {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 9pt;
                }
            }
            
            /* Formal print header */
            .page-header {
                border: 2px solid #000 !important;
                padding: 16px 20px !important;
            }
            
            .page-header::before {
                content: "WESTERN PHILIPPINES UNIVERSITY";
                display: block;
                font-size: 10pt;
                font-weight: bold;
                letter-spacing: 1px;
                margin-bottom: 4px;
            }
            
            .page-header::after {
                content: "Report Generated: <?php echo date('F j, Y') . ' at ' . date('g:i A'); ?>";
                display: block;
                font-size: 10pt;
                margin-top: 10px;
                font-style: normal;
            }
            
            /* Ensure proper spacing */
            .row {
                page-break-inside: avoid;
            }
            
            /* Remove rounded corners and shadows globally */
            * {
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            
            /* Table styling for formal appearance */
            table {
                border-collapse: collapse;
                width: 100%;
            }
            
            th, td {
                border: 1px solid #000;
                padding: 8px;
            }
            
            th {
                background: #f0f0f0;
                font-weight: bold;
            }
            
            /* Print-only detailed report sections (hidden on screen) */
            .print-report-details {
                display: none;
            }
            
            .print-report-details .print-section {
                margin-bottom: 24px;
                page-break-inside: avoid;
            }
            
            .print-report-details .print-section-title {
                font-size: 12pt;
                font-weight: bold;
                margin: 20px 0 10px 0;
                padding: 6px 0;
                border-bottom: 2px solid #000;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .print-report-details table {
                font-size: 9pt;
                margin-bottom: 15px;
            }
            
            .print-report-details .dept-employee-table {
                margin-bottom: 20px;
            }
            
            .print-report-details .dept-employee-table caption {
                font-weight: bold;
                font-size: 10pt;
                padding: 8px 0;
                text-align: left;
                caption-side: top;
            }
        }
        
        @media print {
            .print-report-details {
                display: block !important;
            }
            
            .screen-only-print-hide {
                display: none !important;
            }
            
            .chart-container {
                page-break-inside: avoid;
            }
            
            nav, .sidebar, .navbar, header .btn, .page-header .btn,
            .modal, .modal-backdrop {
                display: none !important;
            }
            
            .main-content {
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Analytics',
                    '',
                    'fas fa-chart-line',
                    [
                      
                    ],
                    '<form method="get" class="d-inline-flex flex-wrap align-items-center gap-2 no-print" id="reportDateForm">
                        <label class="d-flex align-items-center gap-1 mb-0 small">
                            <span>Start:</span>
                            <input type="date" name="report_start" value="' . htmlspecialchars($reportStartDate) . '" class="form-control form-control-sm" style="width: 140px;">
                        </label>
                        <label class="d-flex align-items-center gap-1 mb-0 small">
                            <span>End:</span>
                            <input type="date" name="report_end" value="' . htmlspecialchars($reportEndDate) . '" class="form-control form-control-sm" style="width: 140px;">
                        </label>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Apply</button>
                        <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Print Report</button>
                    </form>'
                );
                ?>

                <?php displayMessage(); ?>
                
                <!-- Quick Stats -->
                <div class="row g-2 mb-3">
                    <div class="col-md-3 col-6">
                        <div class="analytics-card">
                            <div class="metric-box">
                                <div class="metric-value"><?php echo $stats['total_faculty']; ?></div>
                                <div class="metric-label">Total Faculty</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="analytics-card">
                            <div class="metric-box">
                                <div class="metric-value text-success"><?php echo $stats['pds_submitted']; ?></div>
                                <div class="metric-label">PDS Submitted</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="analytics-card">
                            <div class="metric-box">
                                <div class="metric-value text-warning"><?php echo $stats['pending_submissions']; ?></div>
                                <div class="metric-label">Pending Review</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="analytics-card">
                            <div class="metric-box">
                                <div class="metric-value text-info"><?php echo $stats['active_requirements']; ?></div>
                                <div class="metric-label">Active Requirements</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- User Analytics Report -->
                <div class="section-header">
                    <i class="fas fa-users-cog me-2"></i>User Analytics Report (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <div class="analytics-card user-analytics-card" data-bs-toggle="modal" data-bs-target="#modalActiveUsers" role="button" tabindex="0">
                            <h6><i class="fas fa-user-check"></i>Active Users</h6>
                            <div class="metric-box">
                                <div class="metric-value text-primary"><?php echo $activeUsersCount; ?></div>
                                <div class="metric-label">Users actively accessing the system</div>
                                <small class="text-muted d-block mt-1 screen-only-print-hide">Click to view users</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="analytics-card user-analytics-card" data-bs-toggle="modal" data-bs-target="#modalPardonRequests" role="button" tabindex="0">
                            <h6><i class="fas fa-file-signature"></i>Pardon Requests</h6>
                            <div class="metric-box">
                                <div class="metric-value text-warning"><?php echo $pardonRequestUsersCount; ?></div>
                                <div class="metric-label">Users requesting pardon</div>
                                <small class="text-muted d-block mt-1 screen-only-print-hide">Click to view users</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="analytics-card user-analytics-card" data-bs-toggle="modal" data-bs-target="#modalLateTimeIn" role="button" tabindex="0">
                            <h6><i class="fas fa-clock"></i>Late Time-In</h6>
                            <div class="metric-box">
                                <div class="metric-value text-danger"><?php echo $lateTimeInUsersCount; ?></div>
                                <div class="metric-label">Users with late time-in</div>
                                <small class="text-muted d-block mt-1 screen-only-print-hide">Click to view users</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Submissions & Activity Overview -->
                <div class="section-header">
                    <i class="fas fa-file-alt me-2"></i>Submissions & Activity
                </div>
                <div class="row g-2">
                    <div class="col-lg-8">
                        <div class="analytics-card">
                            <h6><i class="fas fa-chart-line"></i>Submission Activity (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h6>
                            <div class="chart-container">
                                <canvas id="submissionTrendsChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="analytics-card">
                            <h6><i class="fas fa-tasks"></i>Submission Status</h6>
                            <div class="chart-container">
                                <canvas id="submissionStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- System Stats -->
                <div class="section-header">
                    <i class="fas fa-bell me-2"></i>Notifications & Announcements
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-lg-6">
                        <div class="analytics-card">
                            <h6><i class="fas fa-bullhorn"></i>Announcements</h6>
                            <div class="row g-2">
                                <div class="col-4">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value"><?php echo $announcementStats['total_announcements']; ?></div>
                                        <div class="stat-mini-label">Total</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value text-success"><?php echo $announcementStats['active_announcements']; ?></div>
                                        <div class="stat-mini-label">Active</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value text-danger"><?php echo $announcementStats['urgent_announcements']; ?></div>
                                        <div class="stat-mini-label">Urgent</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="analytics-card">
                            <h6><i class="fas fa-envelope"></i>Notifications (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h6>
                            <div class="row g-2">
                                <div class="col-4">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value"><?php echo $notificationStats['total_notifications']; ?></div>
                                        <div class="stat-mini-label">Sent</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value text-warning"><?php echo $notificationStats['unread_notifications']; ?></div>
                                        <div class="stat-mini-label">Unread</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value text-info"><?php echo $notificationStats['announcement_notifications']; ?></div>
                                        <div class="stat-mini-label">Updates</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Faculty Distribution -->
                <div class="section-header">
                    <i class="fas fa-users me-2"></i>Faculty Distribution
                </div>
                <div class="row g-2">
                    <div class="col-lg-6">
                        <div class="analytics-card">
                            <h6><i class="fas fa-building"></i>By Department</h6>
                            <p class="small text-muted mb-2 screen-only-print-hide">Click a department bar to view employees</p>
                            <div class="chart-container" style="cursor: pointer;">
                                <canvas id="departmentChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="analytics-card">
                            <h6><i class="fas fa-user-plus"></i>Registration Trend (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h6>
                            <div class="chart-container">
                                <canvas id="registrationsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Requirement Compliance -->
                <div class="section-header">
                    <i class="fas fa-clipboard-check me-2"></i>Requirement Compliance
                </div>
                <div class="row g-2">
                    <div class="col-12">
                        <div class="analytics-card">
                            <h6><i class="fas fa-percentage"></i>Submission Rates by Requirement</h6>
                            <?php if (empty($deadlineCompliance)): ?>
                                <p class="text-muted text-center py-2 mb-0" style="font-size: 0.9rem;">No active requirements to display</p>
                            <?php else: ?>
                                <?php foreach ($deadlineCompliance as $req): ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.85rem;">
                                            <strong><?php echo htmlspecialchars($req['requirement']); ?></strong>
                                            <span class="text-muted">
                                                <?php echo $req['submitted_count']; ?>/<?php echo $req['total_faculty']; ?> submitted
                                                <?php if ($req['deadline']): ?>
                                                    <span class="ms-2"><i class="fas fa-calendar-alt me-1"></i><?php echo formatDate($req['deadline'], 'M j'); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="compliance-bar">
                                            <div class="compliance-fill" style="width: <?php echo $req['compliance_rate']; ?>%">
                                                <?php echo $req['compliance_rate']; ?>%
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Info -->
                <div class="section-header">
                    <i class="fas fa-info-circle me-2"></i>Additional Details
                </div>
                <div class="row g-2">
                    <div class="col-lg-4">
                        <div class="analytics-card">
                            <h6><i class="fas fa-file-alt"></i>PDS Status</h6>
                            <div class="chart-container" style="height: 200px;">
                                <canvas id="pdsStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="analytics-card">
                            <h6><i class="fas fa-tasks"></i>Top Requirements</h6>
                            <?php if (empty($requirementAssignments)): ?>
                                <p class="text-muted text-center py-2 mb-0" style="font-size: 0.85rem;">No data available</p>
                            <?php else: ?>
                                <div style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach (array_slice($requirementAssignments, 0, 5) as $req): ?>
                                        <?php
                                        $completionRate = $req['assigned_count'] > 0 
                                            ? round(($req['submitted_count'] / $req['assigned_count']) * 100) 
                                            : 0;
                                        ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom" style="font-size: 0.85rem;">
                                            <div class="flex-grow-1 me-2">
                                                <div class="text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($req['title']); ?></div>
                                                <small class="text-muted"><?php echo $req['submitted_count']; ?>/<?php echo $req['assigned_count']; ?> done</small>
                                            </div>
                                            <span class="badge bg-<?php echo $completionRate >= 75 ? 'success' : ($completionRate >= 50 ? 'warning' : 'danger'); ?>">
                                                <?php echo $completionRate; ?>%
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="analytics-card">
                            <h6><i class="fas fa-history"></i>Recent Activity (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h6>
                            <?php if (empty($recentActivity)): ?>
                                <p class="text-muted text-center py-2 mb-0" style="font-size: 0.85rem;">No recent activity</p>
                            <?php else: ?>
                                <div style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach (array_slice($recentActivity, 0, 6) as $activity): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom" style="font-size: 0.85rem;">
                                            <span class="text-truncate me-2" style="max-width: 180px;"><?php echo htmlspecialchars($activity['action']); ?></span>
                                            <span class="badge bg-secondary"><?php echo $activity['count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Print Report: Detailed Data (visible only when printing) -->
                <div class="print-report-details">
                    <div class="print-section-title">1. USER ANALYTICS REPORT &ndash; DETAILED LISTS (<?php echo htmlspecialchars($reportStartFormatted); ?> to <?php echo htmlspecialchars($reportEndFormatted); ?>)</div>
                    
                    <div class="print-section">
                        <strong>1.1 Active Users (<?php echo $activeUsersCount; ?> total)</strong>
                        <?php if (empty($activeUsersList)): ?>
                            <p>No active users for this period.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Account Type</th></tr></thead>
                                <tbody>
                                    <?php foreach ($activeUsersList as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])); ?></td>
                                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('_', ' ', $u['user_type'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section">
                        <strong>1.2 Users Requesting Pardon (<?php echo $pardonRequestUsersCount; ?> total)</strong>
                        <?php if (empty($pardonRequestUsersList)): ?>
                            <p>No pardon requests for this period.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>#</th><th>Employee ID</th><th>Name</th><th>Department</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pardonRequestUsersList as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($u['employee_id']); ?></td>
                                        <td><?php echo htmlspecialchars(trim(($u['employee_first_name'] ?? '') . ' ' . ($u['employee_last_name'] ?? '')) ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($u['employee_department'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section">
                        <strong>1.3 Users with Late Time-In (<?php echo $lateTimeInUsersCount; ?> total)</strong>
                        <?php if (empty($lateTimeInUsersList)): ?>
                            <p>No late time-in records for this period.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>#</th><th>Employee ID</th><th>Name</th><th>Department</th></tr></thead>
                                <tbody>
                                    <?php foreach ($lateTimeInUsersList as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($u['employee_id']); ?></td>
                                        <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($u['department'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">2. SUBMISSION ACTIVITY (<?php echo htmlspecialchars($reportStartFormatted); ?> to <?php echo htmlspecialchars($reportEndFormatted); ?>)</div>
                    <div class="print-section">
                        <?php if (empty($submissionTrends)): ?>
                            <p>No submission activity.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Date</th><th>Submissions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($submissionTrends as $t): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($t['date']); ?></td>
                                        <td><?php echo (int)$t['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">3. SUBMISSION STATUS BREAKDOWN</div>
                    <div class="print-section">
                        <?php if (empty($submissionStatus)): ?>
                            <p>No data.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                                <tbody>
                                    <?php foreach ($submissionStatus as $s): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(ucfirst($s['status'])); ?></td>
                                        <td><?php echo (int)$s['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">4. FACULTY DISTRIBUTION BY DEPARTMENT &ndash; EMPLOYEE LISTS</div>
                    <?php foreach ($employeesByDepartment as $dept => $employees): ?>
                    <div class="print-section dept-employee-table">
                        <table class="table table-bordered">
                            <caption><?php echo htmlspecialchars($dept); ?> (<?php echo count($employees); ?> employees)</caption>
                            <thead><tr><th>#</th><th>Name</th><th>Employee ID</th><th>Position</th><th>Email</th></tr></thead>
                            <tbody>
                                <?php foreach ($employees as $i => $emp): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['position']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['email']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="print-section-title">5. DEPARTMENT SUMMARY</div>
                    <div class="print-section">
                        <?php if (empty($departmentDistribution)): ?>
                            <p>No data.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Department</th><th>Faculty Count</th></tr></thead>
                                <tbody>
                                    <?php foreach ($departmentDistribution as $d): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($d['department']); ?></td>
                                        <td><?php echo (int)$d['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">6. REGISTRATION TREND (<?php echo htmlspecialchars($reportStartFormatted); ?> to <?php echo htmlspecialchars($reportEndFormatted); ?>)</div>
                    <div class="print-section">
                        <?php if (empty($monthlyRegistrations)): ?>
                            <p>No data.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Month</th><th>New Faculty</th></tr></thead>
                                <tbody>
                                    <?php foreach ($monthlyRegistrations as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['month']); ?></td>
                                        <td><?php echo (int)$r['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">7. REQUIREMENT COMPLIANCE</div>
                    <div class="print-section">
                        <?php if (empty($deadlineCompliance)): ?>
                            <p>No active requirements.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Requirement</th><th>Submitted</th><th>Total</th><th>Rate</th><th>Deadline</th></tr></thead>
                                <tbody>
                                    <?php foreach ($deadlineCompliance as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['requirement']); ?></td>
                                        <td><?php echo (int)$r['submitted_count']; ?></td>
                                        <td><?php echo (int)$r['total_faculty']; ?></td>
                                        <td><?php echo $r['compliance_rate']; ?>%</td>
                                        <td><?php echo $r['deadline'] ? htmlspecialchars(formatDate($r['deadline'], 'M j, Y')) : '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">8. PDS STATUS</div>
                    <div class="print-section">
                        <?php if (empty($pdsStatus)): ?>
                            <p>No data.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pdsStatus as $p): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(ucfirst($p['status'])); ?></td>
                                        <td><?php echo (int)$p['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">9. TOP REQUIREMENTS</div>
                    <div class="print-section">
                        <?php if (empty($requirementAssignments)): ?>
                            <p>No data.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Requirement</th><th>Assigned</th><th>Submitted</th><th>Completion %</th></tr></thead>
                                <tbody>
                                    <?php foreach ($requirementAssignments as $r): ?>
                                    <?php $completionRate = $r['assigned_count'] > 0 ? round(($r['submitted_count'] / $r['assigned_count']) * 100) : 0; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['title']); ?></td>
                                        <td><?php echo (int)$r['assigned_count']; ?></td>
                                        <td><?php echo (int)$r['submitted_count']; ?></td>
                                        <td><?php echo $completionRate; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">10. RECENT ACTIVITY (<?php echo htmlspecialchars($reportStartFormatted); ?> to <?php echo htmlspecialchars($reportEndFormatted); ?>)</div>
                    <div class="print-section">
                        <?php if (empty($recentActivity)): ?>
                            <p>No recent activity.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead><tr><th>Action</th><th>Count</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentActivity as $a): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($a['action']); ?></td>
                                        <td><?php echo (int)$a['count']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <div class="print-section-title">11. ANNOUNCEMENTS &amp; NOTIFICATIONS</div>
                    <div class="print-section">
                        <table class="table table-bordered">
                            <thead><tr><th>Metric</th><th>Value</th></tr></thead>
                            <tbody>
                                <tr><td>Total Announcements</td><td><?php echo (int)($announcementStats['total_announcements'] ?? 0); ?></td></tr>
                                <tr><td>Active Announcements</td><td><?php echo (int)($announcementStats['active_announcements'] ?? 0); ?></td></tr>
                                <tr><td>Urgent Announcements</td><td><?php echo (int)($announcementStats['urgent_announcements'] ?? 0); ?></td></tr>
                                <tr><td>Total Notifications (30 days)</td><td><?php echo (int)($notificationStats['total_notifications'] ?? 0); ?></td></tr>
                                <tr><td>Unread Notifications</td><td><?php echo (int)($notificationStats['unread_notifications'] ?? 0); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <p class="text-muted mt-4" style="font-size: 9pt;">Report generated: <?php echo date('F j, Y g:i A'); ?> | WPU Faculty System</p>
                </div>
            </main>
        </div>
    </div>
    
    <!-- User Analytics Modals -->
    <div class="modal fade" id="modalActiveUsers" tabindex="-1" aria-labelledby="modalActiveUsersLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalActiveUsersLabel"><i class="fas fa-user-check me-2"></i>Active Users (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Users who logged in and actively accessed the system during the report period.</p>
                    <?php if (empty($activeUsersList)): ?>
                        <p class="text-muted text-center py-4">No active users found for this period.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Account Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeUsersList as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])); ?></td>
                                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', $u['user_type'])); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalPardonRequests" tabindex="-1" aria-labelledby="modalPardonRequestsLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPardonRequestsLabel"><i class="fas fa-file-signature me-2"></i>Users Requesting Pardon (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Employees who submitted pardon requests during the report period.</p>
                    <?php if (empty($pardonRequestUsersList)): ?>
                        <p class="text-muted text-center py-4">No pardon requests found for this period.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Department</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pardonRequestUsersList as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($u['employee_id']); ?></td>
                                        <td><?php echo htmlspecialchars(trim(($u['employee_first_name'] ?? '') . ' ' . ($u['employee_last_name'] ?? '')) ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($u['employee_department'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalLateTimeIn" tabindex="-1" aria-labelledby="modalLateTimeInLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalLateTimeInLabel"><i class="fas fa-clock me-2"></i>Users with Late Time-In (<?php echo htmlspecialchars($reportStartFormatted); ?> &ndash; <?php echo htmlspecialchars($reportEndFormatted); ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Employees who clocked in after their official schedule during the report period.</p>
                    <?php if (empty($lateTimeInUsersList)): ?>
                        <p class="text-muted text-center py-4">No late time-in records found for this period.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Department</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lateTimeInUsersList as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($u['employee_id']); ?></td>
                                        <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($u['department'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Department Employees Modal -->
    <div class="modal fade" id="modalDepartmentEmployees" tabindex="-1" aria-labelledby="modalDepartmentEmployeesLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDepartmentEmployeesLabel"><i class="fas fa-building me-2"></i>Employees by Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="departmentEmployeesContent">
                        <p class="text-muted text-center py-4">Select a department from the chart.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php admin_page_scripts(); ?>
    
    <script>
        // Chart.js configuration - Compact and clean
        Chart.defaults.color = '#64748b';
        Chart.defaults.font.size = 11;
        Chart.defaults.font.family = "'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif";
        
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { 
                    display: true,
                    position: 'bottom',
                    labels: { 
                        padding: 8,
                        font: { size: 10 },
                        boxWidth: 12
                    }
                }
            }
        };
        
        // Submission Activity Line Chart
        const submissionTrendsData = <?php echo json_encode($submissionTrends); ?>;
        new Chart(document.getElementById('submissionTrendsChart'), {
            type: 'line',
            data: {
                labels: submissionTrendsData.map(d => {
                    const date = new Date(d.date);
                    return (date.getMonth() + 1) + '/' + date.getDate();
                }),
                datasets: [{
                    label: 'Daily Submissions',
                    data: submissionTrendsData.map(d => d.count),
                    borderColor: '#0077cc',
                    backgroundColor: 'rgba(0, 119, 204, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 2,
                    pointHoverRadius: 4
                }]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: { 
                        beginAtZero: true, 
                        ticks: { precision: 0 },
                        grid: { color: '#f1f5f9' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
        
        // Submission Status Doughnut
        const submissionStatusData = <?php echo json_encode($submissionStatus); ?>;
        new Chart(document.getElementById('submissionStatusChart'), {
            type: 'doughnut',
            data: {
                labels: submissionStatusData.map(d => d.status.charAt(0).toUpperCase() + d.status.slice(1)),
                datasets: [{
                    data: submissionStatusData.map(d => d.count),
                    backgroundColor: ['#f59e0b', '#10b981', '#ef4444', '#0ea5e9'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: chartOptions
        });
        
        // Department Bar Chart (clickable)
        const departmentData = <?php echo json_encode($departmentDistribution); ?>;
        const employeesByDepartment = <?php echo json_encode($employeesByDepartment); ?>;
        const departmentChart = new Chart(document.getElementById('departmentChart'), {
            type: 'bar',
            data: {
                labels: departmentData.map(d => {
                    const dept = d.department;
                    return dept.length > 20 ? dept.substring(0, 20) + '...' : dept;
                }),
                datasets: [{
                    label: 'Faculty',
                    data: departmentData.map(d => d.count),
                    backgroundColor: '#003366',
                    borderRadius: 4
                }]
            },
            options: {
                ...chartOptions,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            afterLabel: () => 'Click to view employees'
                        }
                    }
                },
                onClick: (evt, elements) => {
                    if (elements.length > 0) {
                        const idx = elements[0].index;
                        const dept = departmentData[idx].department;
                        const employees = employeesByDepartment[dept] || [];
                        const modal = document.getElementById('modalDepartmentEmployees');
                        const titleEl = document.getElementById('modalDepartmentEmployeesLabel');
                        const contentEl = document.getElementById('departmentEmployeesContent');
                        titleEl.innerHTML = '<i class="fas fa-building me-2"></i>' + dept + ' (' + employees.length + ' employees)';
                        if (employees.length === 0) {
                            contentEl.innerHTML = '<p class="text-muted text-center py-4">No employees in this department.</p>';
                        } else {
                            let html = '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>#</th><th>Name</th><th>Employee ID</th><th>Position</th><th>Email</th></tr></thead><tbody>';
                            employees.forEach((emp, i) => {
                                html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(emp.name) + '</td><td>' + escapeHtml(emp.employee_id) + '</td><td>' + escapeHtml(emp.position) + '</td><td>' + escapeHtml(emp.email) + '</td></tr>';
                            });
                            html += '</tbody></table></div>';
                            contentEl.innerHTML = html;
                        }
                        if (typeof bootstrap !== 'undefined') {
                            new bootstrap.Modal(modal).show();
                        }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        ticks: { precision: 0 },
                        grid: { color: '#f1f5f9' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Registration Trend
        const registrationsData = <?php echo json_encode($monthlyRegistrations); ?>;
        new Chart(document.getElementById('registrationsChart'), {
            type: 'bar',
            data: {
                labels: registrationsData.map(d => {
                    const [year, month] = d.month.split('-');
                    const date = new Date(year, month - 1);
                    return date.toLocaleString('default', { month: 'short', year: '2-digit' });
                }),
                datasets: [{
                    label: 'New Faculty',
                    data: registrationsData.map(d => d.count),
                    backgroundColor: '#0077cc',
                    borderRadius: 4
                }]
            },
            options: {
                ...chartOptions,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        ticks: { precision: 0 },
                        grid: { color: '#f1f5f9' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
        
        // User analytics cards: Enter key opens modal
        document.querySelectorAll('.user-analytics-card').forEach(card => {
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const target = this.getAttribute('data-bs-target');
                    if (target) {
                        const modal = document.querySelector(target);
                        if (modal && typeof bootstrap !== 'undefined') {
                            new bootstrap.Modal(modal).show();
                        }
                    }
                }
            });
        });
        
        // PDS Status Pie
        const pdsStatusData = <?php echo json_encode($pdsStatus); ?>;
        new Chart(document.getElementById('pdsStatusChart'), {
            type: 'pie',
            data: {
                labels: pdsStatusData.map(d => d.status.charAt(0).toUpperCase() + d.status.slice(1)),
                datasets: [{
                    data: pdsStatusData.map(d => d.count),
                    backgroundColor: ['#94a3b8', '#f59e0b', '#10b981', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: chartOptions
        });
    </script>
</body>
</html>

