<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/tarf_request_attendance_sync.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Super_admin approves/rejects pardon requests; regular admin has view-only access
$canApproveReject = isSuperAdmin();

// Handle approve/reject (super_admin only)
if ($canApproveReject && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = intval($_POST['request_id'] ?? 0);
    $action = $_POST['action'];
    $review_notes = $_POST['review_notes'] ?? '';
    
    try {
        $stmt = $db->prepare("SELECT pr.*, COALESCE(fp.department, pr.employee_department) as department 
                              FROM pardon_requests pr
                              LEFT JOIN faculty_profiles fp ON pr.employee_id = fp.employee_id
                              WHERE pr.id = ? LIMIT 1");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request || $request['status'] !== 'pending') {
            $_SESSION['error'] = 'Invalid or already processed request';
        } elseif ($action === 'approve') {
            $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'pardon_weekly_limit' LIMIT 1");
            $stmt->execute();
            $limitSetting = $stmt->fetch(PDO::FETCH_ASSOC);
            $weeklyLimit = $limitSetting ? intval($limitSetting['setting_value']) : 3;
            
            $logDateObj = new DateTime($request['log_date']);
            $dayOfWeek = (int)$logDateObj->format('w');
            $dayOfWeek = ($dayOfWeek == 0) ? 6 : $dayOfWeek - 1;
            $weekStart = clone $logDateObj;
            $weekStart->modify('-' . $dayOfWeek . ' days');
            $weekStart->setTime(0, 0, 0);
            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');
            $weekEnd->setTime(23, 59, 59);
            $weekStartDateStr = $weekStart->format('Y-m-d');
            $weekEndDateStr = $weekEnd->format('Y-m-d');
            
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM pardon_requests WHERE employee_id = ? AND status = 'approved' AND log_date >= ? AND log_date <= ? AND id != ?");
            $stmt->execute([$request['employee_id'], $weekStartDateStr, $weekEndDateStr, $request_id]);
            $approvedThisWeek = intval($stmt->fetch(PDO::FETCH_ASSOC)['count']);
            
            if ($approvedThisWeek >= $weeklyLimit) {
                $_SESSION['error'] = "Cannot approve: Employee has reached the weekly pardon limit of {$weeklyLimit} for this week.";
            } else {
                $pardonType = $request['pardon_type'] ?? 'ordinary_pardon';
                $isLeaveType = !in_array($pardonType, ['ordinary_pardon', 'tarf_ntarf', 'work_from_home']);
                $isTarfNtarfType = ($pardonType === 'tarf_ntarf');
                $anchorDate = date('Y-m-d', strtotime($request['log_date']));
                $datesToApply = [$anchorDate];
                if (!empty($request['pardon_covered_dates'])) {
                    $decodedDates = json_decode($request['pardon_covered_dates'], true);
                    if (is_array($decodedDates)) {
                        foreach ($decodedDates as $d) {
                            $ds = date('Y-m-d', strtotime(trim((string) $d)));
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ds)) {
                                $datesToApply[] = $ds;
                            }
                        }
                        $datesToApply = array_values(array_unique($datesToApply));
                        sort($datesToApply);
                    }
                }
                try {
                    $db->beginTransaction();
                    if ($isTarfNtarfType) {
                        tarf_request_ensure_tarf_tables($db);
                        tarf_calendar_kind_ensure_column($db);
                    }
                    foreach ($datesToApply as $dStr) {
                        $stmtLid = $db->prepare("SELECT id FROM attendance_logs WHERE employee_id = ? AND DATE(log_date) = ? LIMIT 1");
                        $stmtLid->execute([$request['employee_id'], $dStr]);
                        $logRow = $stmtLid->fetch(PDO::FETCH_ASSOC);
                        if (!$logRow) {
                            try {
                                $insLog = $db->prepare("INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks) VALUES (?, ?, NULL, NULL, NULL, NULL, NULL)");
                                $insLog->execute([$request['employee_id'], $dStr]);
                                $applyLogId = (int) $db->lastInsertId();
                            } catch (PDOException $pex) {
                                $msg = $pex->getMessage();
                                if (stripos($msg, 'Duplicate') === false && stripos($msg, 'UNIQUE') === false && (int) $pex->getCode() !== 23000) {
                                    throw $pex;
                                }
                                $stmtLid->execute([$request['employee_id'], $dStr]);
                                $logRow = $stmtLid->fetch(PDO::FETCH_ASSOC);
                                if (!$logRow) {
                                    throw new Exception('Could not create attendance log for ' . $dStr);
                                }
                                $applyLogId = (int) $logRow['id'];
                            }
                        } else {
                            $applyLogId = (int) $logRow['id'];
                        }
                        if ($isLeaveType) {
                            $updateStmt = $db->prepare("UPDATE attendance_logs SET time_in = NULL, lunch_out = NULL, lunch_in = NULL, time_out = NULL, remarks = 'LEAVE' WHERE id = ?");
                            $updateStmt->execute([$applyLogId]);
                        } elseif ($isTarfNtarfType) {
                            // Mirror approved TARF/NTARF pardon to calendar tarf + tarf_employees and mark
                            // attendance_logs row as TARF so DTR / view_logs / Employee Management indicate TARF.
                            $tarfTitle = trim((string) ($request['reason'] ?? ''));
                            if ($tarfTitle === '') {
                                $tarfTitle = 'TARF/NTARF Pardon #' . $request_id;
                            }
                            $titleShort = function_exists('mb_substr')
                                ? mb_substr($tarfTitle, 0, 220)
                                : substr($tarfTitle, 0, 220);

                            $calendarTarfId = 0;
                            try {
                                $insTarf = $db->prepare('INSERT INTO tarf (title, description, date, calendar_kind, created_at) VALUES (?, ?, ?, ?, NOW())');
                                $insTarf->execute([
                                    $titleShort,
                                    'pardon_request_id:' . $request_id,
                                    $dStr,
                                    'travel',
                                ]);
                                $calendarTarfId = (int) $db->lastInsertId();
                            } catch (Exception $eIns) {
                                try {
                                    $insSimple = $db->prepare('INSERT INTO tarf (title, description, date, created_at) VALUES (?, ?, ?, NOW())');
                                    $insSimple->execute([$titleShort, 'pardon_request_id:' . $request_id, $dStr]);
                                    $calendarTarfId = (int) $db->lastInsertId();
                                    try {
                                        $db->prepare('UPDATE tarf SET calendar_kind = ? WHERE id = ?')->execute(['travel', $calendarTarfId]);
                                    } catch (Exception $e) { /* ignore */ }
                                } catch (Exception $eIns2) {
                                    error_log('pardon_requests TARF/NTARF tarf insert: ' . $eIns2->getMessage());
                                    $calendarTarfId = 0;
                                }
                            }
                            if ($calendarTarfId > 0) {
                                try {
                                    $insTe = $db->prepare('INSERT INTO tarf_employees (tarf_id, employee_id) VALUES (?, ?)');
                                    $insTe->execute([$calendarTarfId, $request['employee_id']]);
                                } catch (Exception $eTe) {
                                    error_log('pardon_requests TARF/NTARF tarf_employees insert: ' . $eTe->getMessage());
                                }
                            }

                            $ot = tarf_request_official_times_for_date($request['employee_id'], $dStr, $db);
                            $creditH = tarf_request_credit_hours_from_official_slice($ot);
                            $remarksUse = 'TARF: ' . $titleShort . ' | TARF_HOURS_CREDIT:' . $creditH;
                            if (strlen($remarksUse) > 500) {
                                $remarksUse = substr($remarksUse, 0, 497) . '...';
                            }

                            $updateStmt = $db->prepare('UPDATE attendance_logs SET time_in = NULL, lunch_out = NULL, lunch_in = NULL, time_out = NULL, remarks = ?, tarf_id = ? WHERE id = ?');
                            $updateStmt->execute([$remarksUse, ($calendarTarfId > 0 ? $calendarTarfId : null), $applyLogId]);
                        } else {
                            $updateStmt = $db->prepare("UPDATE attendance_logs SET time_in = ?, lunch_out = ?, lunch_in = ?, time_out = ? WHERE id = ?");
                            $updateStmt->execute([
                                $request['requested_time_in'],
                                $request['requested_lunch_out'] ?: null,
                                $request['requested_lunch_in'] ?: null,
                                $request['requested_time_out'],
                                $applyLogId
                            ]);
                        }
                    }
                    $stmt = $db->prepare("UPDATE pardon_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $review_notes, $request_id]);
                    $db->commit();
                    $datesNote = count($datesToApply) > 1 ? (' (' . count($datesToApply) . ' dates)') : '';
                    logAction('PARDON_APPROVED', "Approved pardon request ID: $request_id for employee {$request['employee_id']} (anchor: {$request['log_date']})$datesNote");
                    $_SESSION['success'] = count($datesToApply) > 1
                        ? 'Pardon approved and ' . count($datesToApply) . ' attendance day(s) updated.'
                        : 'Pardon request approved and log updated successfully';
                } catch (Exception $ex) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = 'Could not approve pardon: ' . $ex->getMessage();
                }
            }
        } elseif ($action === 'reject') {
            $stmt = $db->prepare("UPDATE pardon_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $review_notes, $request_id]);
            logAction('PARDON_REJECTED', "Rejected pardon request ID: $request_id for employee {$request['employee_id']} (log date: {$request['log_date']})");
            $_SESSION['success'] = 'Pardon request rejected';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error processing request: ' . $e->getMessage();
    }
    
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/admin/pardon_requests.php' . ($_GET ? '?' . http_build_query($_GET) : ''), $basePath));
}

// Get filter parameters
$search = $_GET['search'] ?? '';

// Build query - main table shows only pending requests; approved/rejected are in History
$whereClause = "pr.status = 'pending'";
$params = [];

if ($search) {
    $whereClause .= " AND (pr.employee_id LIKE ? OR COALESCE(u.first_name, pr.employee_first_name) LIKE ? OR COALESCE(u.last_name, pr.employee_last_name) LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Pagination
$perPage = 25;
$countSql = "SELECT COUNT(*) 
             FROM pardon_requests pr
             LEFT JOIN faculty_profiles fp ON pr.employee_id = fp.employee_id
             LEFT JOIN users u ON fp.user_id = u.id
             WHERE $whereClause";
$pagination = getPaginationParams($db, $countSql, $params, $perPage);

// Get pardon requests with department information
// Use stored employee info for deleted accounts, fallback to joined data for active accounts
$sql = "SELECT pr.*, 
               COALESCE(u.first_name, pr.employee_first_name) as first_name,
               COALESCE(u.last_name, pr.employee_last_name) as last_name,
               COALESCE(fp.position, 'Former Employee') as position,
               COALESCE(fp.department, pr.employee_department, 'N/A') as department,
               reviewer.first_name as reviewer_first_name,
               reviewer.last_name as reviewer_last_name,
               CASE WHEN u.id IS NULL THEN 1 ELSE 0 END as is_deleted_account
        FROM pardon_requests pr
        LEFT JOIN faculty_profiles fp ON pr.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        LEFT JOIN users reviewer ON pr.reviewed_by = reviewer.id
        WHERE $whereClause
        ORDER BY pr.created_at DESC
        LIMIT {$pagination['limit']} OFFSET {$pagination['offset']}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// History: approved and rejected pardon requests (for View History modal)
$historySql = "SELECT pr.*, 
               COALESCE(u.first_name, pr.employee_first_name) as first_name,
               COALESCE(u.last_name, pr.employee_last_name) as last_name,
               COALESCE(fp.department, pr.employee_department, 'N/A') as department,
               reviewer.first_name as reviewer_first_name,
               reviewer.last_name as reviewer_last_name
        FROM pardon_requests pr
        LEFT JOIN faculty_profiles fp ON pr.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        LEFT JOIN users reviewer ON pr.reviewed_by = reviewer.id
        WHERE pr.status IN ('approved', 'rejected')
        ORDER BY pr.reviewed_at DESC
        LIMIT 100";
$historyStmt = $db->query($historySql);
$historyRequests = $historyStmt ? $historyStmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Pardon Requests', 'Review and manage employee pardon requests');
    ?>
    <style>
        .comparison-table {
            font-size: 0.875rem;
        }
        .comparison-table td {
            padding: 0.5rem;
        }
        .original-value {
            color: #6c757d;
            text-decoration: line-through;
        }
        .requested-value {
            color: #198754;
            font-weight: 600;
        }
        .pardon-table {
            font-size: 0.9rem;
        }
        .pardon-table thead th {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
            padding: 1rem 0.75rem;
        }
        .pardon-table tbody td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
        }
        .pardon-table tbody tr {
            transition: all 0.2s ease;
        }
        .pardon-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .employee-info {
            display: flex;
            flex-direction: column;
        }
        .employee-name {
            font-weight: 600;
            color: #212529;
            margin-bottom: 0.25rem;
        }
        .employee-id {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .btn-view {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
            color: white;
        }
        .detail-section {
            margin-bottom: 1.5rem;
        }
        .detail-section:last-child {
            margin-bottom: 0;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        .detail-value {
            color: #212529;
            font-size: 0.95rem;
        }
        .time-change-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }
        .time-change-item:last-child {
            margin-bottom: 0;
        }
        .time-label {
            font-weight: 500;
            color: #495057;
        }
        .time-values {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .document-link {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #e7f3ff;
            color: #0066cc;
            border-radius: 6px;
            text-decoration: none;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
        }
        
        /* Override padding for btn-close buttons with data-mobile-fixed */
        .btn-close[data-mobile-fixed="true"],
        button.btn-close[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        .btn-close.btn-close-white[data-mobile-fixed="true"] {
            padding: 0.5rem !important;
        }
        /* History modal styles (approved/rejected pardons) */
        .pardon-history-item { border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; transition: all 0.2s ease; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .pardon-history-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .pardon-history-item.approved { border-left: 4px solid #198754; background: linear-gradient(to right, #f0fdf4 0%, #fff 10%); }
        .pardon-history-item.rejected { border-left: 4px solid #dc3545; background: linear-gradient(to right, #fef2f2 0%, #fff 10%); }
        .pardon-history-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e9ecef; }
        .pardon-history-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-top: 0.75rem; }
    </style>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                $historyBtn = '<button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#historyModal">' .
                    '<i class="fas fa-history me-1"></i>View History' .
                    (!empty($historyRequests) ? ' <span class="badge bg-secondary">' . count($historyRequests) . '</span>' : '') .
                    '</button>';
                admin_page_header(
                    'Pardon Requests',
                    '',
                    'fas fa-gavel',
                    [],
                    $historyBtn
                );
                ?>

                <?php displayMessage(); ?>

                <?php if ($canApproveReject): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-gavel me-2"></i>
                    <strong>HR:</strong> You can approve or reject pardon requests for both faculty and staff.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php else: ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>View-Only Mode:</strong> Pardon requests are approved or rejected by <strong>HR</strong>.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-8">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by Safe Employee ID or name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                            <a href="pardon_requests.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>
                    </div>
                </form>

                <!-- Pardon Requests Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2 text-primary"></i>Pending Pardon Requests
                            <span class="badge bg-warning ms-2"><?php echo $pagination['total']; ?> pending</span>
                            <?php if ($pagination['totalPages'] > 1): ?>
                                <span class="badge bg-info ms-2">Page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?></span>
                            <?php endif; ?>
                        </h5>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-md-none" data-bs-toggle="modal" data-bs-target="#historyModal">
                            <i class="fas fa-history me-1"></i>View History<?php if (!empty($historyRequests)): ?> <span class="badge bg-secondary"><?php echo count($historyRequests); ?></span><?php endif; ?>
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($requests)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <span class="empty-title">No Pending Pardon Requests</span>
                                <p class="mb-0"><?php echo !empty($historyRequests) ? 'All requests have been processed. View approved and rejected requests in History.' : 'No pending pardon requests at this time.'; ?></p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table pardon-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>Employee</th>
                                            <th>Department</th>
                                            <th>Log Date</th>
                                            <th>Requested</th>
                                            <th class="text-center" style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $counter = ($pagination['page'] - 1) * $perPage + 1;
                                        foreach ($requests as $req): 
                                            $fullName = trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''));
                                            $reviewerName = trim(($req['reviewer_first_name'] ?? '') . ' ' . ($req['reviewer_last_name'] ?? ''));
                                            
                                            // Prepare documents array
                                            $documents = [];
                                            if (!empty($req['supporting_documents'])) {
                                                $decoded = json_decode($req['supporting_documents'], true);
                                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                    $documents = $decoded;
                                                } else {
                                                    $documents = [$req['supporting_documents']];
                                                }
                                            }
                                            
                                            // Count changes
                                            $changesCount = 0;
                                            if ($req['original_time_in'] != $req['requested_time_in']) $changesCount++;
                                            if ($req['original_time_out'] != $req['requested_time_out']) $changesCount++;
                                            if ($req['original_lunch_out'] != $req['requested_lunch_out']) $changesCount++;
                                            if ($req['original_lunch_in'] != $req['requested_lunch_in']) $changesCount++;
                                        ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <div class="employee-info">
                                                        <span class="employee-name">
                                                            <?php echo htmlspecialchars($fullName ?: 'Unknown'); ?>
                                                            <?php if (!empty($req['is_deleted_account'])): ?>
                                                                <span class="badge bg-secondary ms-1" style="font-size: 0.7rem;" title="This employee account has been deleted">Deleted Account</span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="employee-id"><?php echo htmlspecialchars($req['employee_id']); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($req['department'])): ?>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars($req['department']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo date('M d, Y', strtotime($req['log_date'])); ?></strong>
                                                    <?php if ($changesCount > 0): ?>
                                                        <br><small class="text-muted"><?php echo $changesCount; ?> change<?php echo $changesCount > 1 ? 's' : ''; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M d, Y', strtotime($req['created_at'])); ?></small><br>
                                                    <small class="text-muted"><?php echo date('g:i A', strtotime($req['created_at'])); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button onclick="viewRequestDetails(<?php echo $req['id']; ?>, event)" 
                                                                class="btn btn-info" 
                                                                title="View Details"
                                                                data-request='<?php echo htmlspecialchars(json_encode($req), ENT_QUOTES, 'UTF-8'); ?>'
                                                                data-employee-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                                                data-reviewer-name="<?php echo htmlspecialchars($reviewerName, ENT_QUOTES); ?>"
                                                                data-documents='<?php echo htmlspecialchars(json_encode($documents), ENT_QUOTES, 'UTF-8'); ?>'>
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($canApproveReject && $req['status'] === 'pending'): ?>
                                                        <button onclick="approveRequest(<?php echo $req['id']; ?>)" class="btn btn-success" title="Approve"><i class="fas fa-check"></i></button>
                                                        <button onclick="rejectRequest(<?php echo $req['id']; ?>)" class="btn btn-danger" title="Reject"><i class="fas fa-times"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($pagination['totalPages'] > 1): ?>
                                <div class="mt-3">
                                    <?php echo renderPagination($pagination['page'], $pagination['totalPages']); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Approve/Reject Modal (super_admin only) -->
    <?php if ($canApproveReject): ?>
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="actionModalTitle">Review Pardon Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="actionForm">
                    <input type="hidden" name="request_id" id="actionRequestId">
                    <input type="hidden" name="action" id="actionType">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Review Notes (Optional)</label>
                            <textarea name="review_notes" id="actionNotes" class="form-control" rows="3" placeholder="Add any notes about your decision..."></textarea>
                        </div>
                        <div class="alert" id="actionAlert"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="actionSubmitBtn"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- View Request Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>Pardon Request Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Employee Information -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-user me-1"></i>Employee Information
                        </div>
                        <div class="detail-value">
                            <strong id="detailEmployeeName"></strong><br>
                            <small class="text-muted" id="detailEmployeeId"></small>
                        </div>
                    </div>

                    <!-- Log Date -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-calendar me-1"></i>Log Date
                        </div>
                        <div class="detail-value" id="detailLogDate"></div>
                    </div>

                    <!-- Pardon Type -->
                    <div class="detail-section" id="detailPardonTypeSection">
                        <div class="detail-label">
                            <i class="fas fa-tag me-1"></i>Type of Pardon
                        </div>
                        <div class="detail-value" id="detailPardonType"></div>
                    </div>

                    <!-- Time Changes -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-clock me-1"></i>Requested Changes
                        </div>
                        <div id="detailTimeChanges"></div>
                    </div>

                    <!-- Justification -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-comment-alt me-1"></i>Justification
                        </div>
                        <div class="detail-value" id="detailReason"></div>
                    </div>

                    <!-- Supporting Documents -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-paperclip me-1"></i>Supporting Documents
                        </div>
                        <div id="detailDocuments"></div>
                    </div>

                    <!-- Status -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-info-circle me-1"></i>Status
                        </div>
                        <div class="detail-value" id="detailStatus"></div>
                    </div>

                    <!-- Request Information -->
                    <div class="detail-section">
                        <div class="detail-label">
                            <i class="fas fa-clock me-1"></i>Request Information
                        </div>
                        <div class="detail-value">
                            <strong>Requested:</strong> <span id="detailRequestedAt"></span>
                        </div>
                    </div>

                    <!-- Review Information -->
                    <div class="detail-section" id="detailReviewSection" style="display: none;">
                        <div class="detail-label">
                            <i class="fas fa-user-check me-1"></i>Review Information
                        </div>
                        <div class="detail-value">
                            <strong>Reviewed by:</strong> <span id="detailReviewedBy"></span><br>
                            <strong>Reviewed at:</strong> <span id="detailReviewedAt"></span><br>
                            <strong>Review Notes:</strong> <span id="detailReviewNotes"></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="text-muted small me-auto" id="detailDeanNote" style="display: none;">
                        <?php if ($canApproveReject): ?>
                        <i class="fas fa-gavel me-1"></i>You can approve or reject this request above.
                        <?php else: ?>
                        <i class="fas fa-info-circle me-1"></i>Actions must be taken by HR
                        <?php endif; ?>
                    </div>
                    <div id="detailActionButtons" class="d-flex gap-2 me-2"></div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- History Modal (approved and rejected pardons) -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="historyModalLabel"><i class="fas fa-history me-2"></i>Pardon Request History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($historyRequests)): ?>
                        <p class="text-muted mb-0">No approved or rejected pardon requests yet.</p>
                    <?php else: ?>
                        <p class="text-muted small mb-3">Approved and rejected pardon requests. Most recent first.</p>
                        <div class="pardon-history-list">
                            <?php foreach ($historyRequests as $req): 
                                $fullName = trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''));
                                $fullName = $fullName ?: 'Unknown';
                                $reviewerName = trim(($req['reviewer_first_name'] ?? '') . ' ' . ($req['reviewer_last_name'] ?? ''));
                                $isApproved = ($req['status'] ?? '') === 'approved';
                                $isRejected = ($req['status'] ?? '') === 'rejected';
                                $documents = [];
                                if (!empty($req['supporting_documents'])) {
                                    $decoded = json_decode($req['supporting_documents'], true);
                                    $documents = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [$req['supporting_documents']];
                                }
                            ?>
                                <div class="pardon-history-item <?php echo $isApproved ? 'approved' : ($isRejected ? 'rejected' : ''); ?>">
                                    <div class="pardon-history-header">
                                        <div>
                                            <strong><?php echo htmlspecialchars($fullName); ?></strong>
                                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($req['employee_id']); ?></span>
                                        </div>
                                        <span class="badge bg-<?php echo $isApproved ? 'success' : 'danger'; ?>"><?php echo ucfirst($req['status'] ?? ''); ?></span>
                                    </div>
                                    <div class="pardon-history-details">
                                        <div><strong><i class="fas fa-calendar me-1"></i>Log Date:</strong> <?php echo date('F j, Y', strtotime($req['log_date'])); ?></div>
                                        <div><strong><i class="fas fa-clock me-1"></i>Reviewed:</strong> <?php echo $req['reviewed_at'] ? date('M j, Y g:i A', strtotime($req['reviewed_at'])) : '-'; ?></div>
                                        <div><strong><i class="fas fa-user-check me-1"></i>By:</strong> <?php echo htmlspecialchars($reviewerName ?: '-'); ?></div>
                                        <?php if (!empty($req['department'])): ?>
                                        <div><strong><i class="fas fa-building me-1"></i>Dept:</strong> <?php echo htmlspecialchars($req['department']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($req['review_notes'])): ?>
                                    <div class="mt-2 small"><strong>Notes:</strong> <?php echo htmlspecialchars($req['review_notes']); ?></div>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary view-history-detail" 
                                                data-request='<?php echo htmlspecialchars(json_encode($req), ENT_QUOTES, 'UTF-8'); ?>'
                                                data-employee-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                                data-reviewer-name="<?php echo htmlspecialchars($reviewerName, ENT_QUOTES); ?>"
                                                data-documents='<?php echo htmlspecialchars(json_encode($documents), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script>
        const canApproveReject = <?php echo $canApproveReject ? 'true' : 'false'; ?>;

        // View Details from History modal - reuse viewRequestDetails logic
        document.querySelectorAll('.view-history-detail').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const req = JSON.parse(this.getAttribute('data-request'));
                const evt = { target: this };
                bootstrap.Modal.getInstance(document.getElementById('historyModal')).hide();
                setTimeout(function() { viewRequestDetails(req.id, evt); }, 300);
            });
        });
        
        function approveRequest(requestId) {
            if (!canApproveReject || !document.getElementById('actionModal')) return;
            document.getElementById('actionRequestId').value = requestId;
            document.getElementById('actionType').value = 'approve';
            document.getElementById('actionModalTitle').textContent = 'Approve Pardon Request';
            document.getElementById('actionSubmitBtn').textContent = 'Approve Request';
            document.getElementById('actionSubmitBtn').className = 'btn btn-success';
            document.getElementById('actionAlert').className = 'alert alert-success';
            document.getElementById('actionAlert').innerHTML = '<i class="fas fa-info-circle"></i> This will update the attendance log(s) for the request date and any additional dates listed on the request (same times or LEAVE, as applicable).';
            document.getElementById('actionNotes').value = '';
            new bootstrap.Modal(document.getElementById('actionModal')).show();
        }
        
        function rejectRequest(requestId) {
            if (!canApproveReject || !document.getElementById('actionModal')) return;
            document.getElementById('actionRequestId').value = requestId;
            document.getElementById('actionType').value = 'reject';
            document.getElementById('actionModalTitle').textContent = 'Reject Pardon Request';
            document.getElementById('actionSubmitBtn').textContent = 'Reject Request';
            document.getElementById('actionSubmitBtn').className = 'btn btn-danger';
            document.getElementById('actionAlert').className = 'alert alert-warning';
            document.getElementById('actionAlert').innerHTML = '<i class="fas fa-exclamation-triangle"></i> The attendance log will remain unchanged.';
            document.getElementById('actionNotes').value = '';
            new bootstrap.Modal(document.getElementById('actionModal')).show();
        }
        
        function viewRequestDetails(requestId, evt) {
            // Find the button that was clicked
            const event = evt || window.event;
            const button = event.target.closest('button[data-request]');
            if (!button) return;

            // Get data from data attributes
            const req = JSON.parse(button.getAttribute('data-request'));
            const employeeName = button.getAttribute('data-employee-name') || 'Unknown';
            const reviewerName = button.getAttribute('data-reviewer-name') || '';
            const documents = JSON.parse(button.getAttribute('data-documents') || '[]');

            // Employee Information
            document.getElementById('detailEmployeeName').textContent = employeeName;
            document.getElementById('detailEmployeeId').textContent = req.employee_id || '-';

            // Log Date (anchor) + multi-day coverage when stored
            const logDate = new Date(req.log_date);
            let logDateText = logDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            if (req.pardon_covered_dates) {
                try {
                    const arr = typeof req.pardon_covered_dates === 'string'
                        ? JSON.parse(req.pardon_covered_dates)
                        : req.pardon_covered_dates;
                    if (Array.isArray(arr) && arr.length > 1) {
                        const anchorStr = (req.log_date || '').toString().substring(0, 10);
                        const rest = arr.filter(function(d) {
                            return String(d).substring(0, 10) !== anchorStr;
                        });
                        if (rest.length) {
                            logDateText += '<br><small class="text-muted">Also covers: ' + rest.map(function(d) {
                                try {
                                    return new Date(String(d).substring(0, 10) + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                                } catch (e) { return d; }
                            }).join(', ') + '</small>';
                        }
                    }
                } catch (e) { /* ignore */ }
            }
            document.getElementById('detailLogDate').innerHTML = logDateText;

            const pardonTypeLabels = {
                ordinary_pardon: 'Ordinary Pardon',
                tarf_ntarf: 'TARF/NTARF',
                work_from_home: 'Work from Home',
                vacation_leave: 'Vacation Leave',
                sick_leave: 'Sick Leave',
                special_privilege_leave: 'Special Privilege Leave',
                forced_mandatory_leave: 'Forced/Mandatory Leave',
                special_emergency_leave: 'Special Emergency Leave',
                maternity_leave: 'Maternity Leave',
                solo_parent_leave: 'Solo Parent Leave',
                magna_carta_leave: 'Magna Carta Leave',
                rehabilitation_leave: 'Rehabilitation Leave'
            };
            const pardonType = req.pardon_type || 'ordinary_pardon';
            document.getElementById('detailPardonType').textContent = pardonTypeLabels[pardonType] || pardonType.replace(/_/g, ' ');

            const isLeaveType = !['ordinary_pardon', 'tarf_ntarf', 'work_from_home'].includes(pardonType);
            const timeChangesContainer = document.getElementById('detailTimeChanges');
            timeChangesContainer.innerHTML = '';
            if (isLeaveType) {
                let leaveMsg = '<div class="text-muted"><i class="fas fa-umbrella-beach me-1"></i>Leave type: DTR shows <strong>LEAVE</strong>; rendered hours follow official time. No time entries.</div>';
                if (req.pardon_covered_dates) {
                    try {
                        const arr = typeof req.pardon_covered_dates === 'string' ? JSON.parse(req.pardon_covered_dates) : req.pardon_covered_dates;
                        if (Array.isArray(arr) && arr.length > 1) {
                            leaveMsg = '<div class="text-muted"><i class="fas fa-umbrella-beach me-1"></i>Leave type: DTR shows <strong>LEAVE</strong> per date; hours from official time. No time entries.</div>';
                        }
                    } catch (e) { /* ignore */ }
                }
                timeChangesContainer.innerHTML = leaveMsg;
            } else {
            
            const timeFields = [
                { label: 'Time In', original: req.original_time_in, requested: req.requested_time_in },
                { label: 'Lunch Out', original: req.original_lunch_out, requested: req.requested_lunch_out },
                { label: 'Lunch In', original: req.original_lunch_in, requested: req.requested_lunch_in },
                { label: 'Time Out', original: req.original_time_out, requested: req.requested_time_out }
            ];

            function formatTime12hr(timeStr) {
                if (!timeStr) return '-';
                const parts = timeStr.substring(0, 8).split(':');
                const h = parseInt(parts[0], 10);
                const m = parts[1] || '00';
                if (h === 0 && m === '00') return '0:00';
                const hour = h % 12 || 12;
                return hour + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM');
            }
            timeFields.forEach(field => {
                if (field.original || field.requested) {
                    const originalTime = field.original ? formatTime12hr(field.original) : '-';
                    const requestedTime = field.requested ? formatTime12hr(field.requested) : '-';
                    const hasChange = field.original !== field.requested;

                    const changeItem = document.createElement('div');
                    changeItem.className = 'time-change-item';
                    changeItem.innerHTML = `
                        <span class="time-label">${field.label}:</span>
                        <div class="time-values">
                            <span class="original-value">${originalTime}</span>
                            ${hasChange ? `<i class="fas fa-arrow-right text-primary mx-2"></i><span class="requested-value">${requestedTime}</span>` : '<span class="text-muted ms-2">(No change)</span>'}
                        </div>
                    `;
                    timeChangesContainer.appendChild(changeItem);
                }
            });
            }

            // Justification
            const reasonContainer = document.getElementById('detailReason');
            if (req.reason) {
                reasonContainer.innerHTML = req.reason.replace(/\n/g, '<br>');
            } else {
                reasonContainer.innerHTML = '<span class="text-muted">No justification provided</span>';
            }

            // Supporting Documents
            const documentsContainer = document.getElementById('detailDocuments');
            if (documents && documents.length > 0) {
                documentsContainer.innerHTML = '';
                documents.forEach((doc, index) => {
                    const docLink = document.createElement('a');
                    // Extract filename from path for display name
                    const fileName = doc.split('/').pop() || `Document ${index + 1}`;
                    docLink.href = `view_file.php?file=${encodeURIComponent(doc)}&name=${encodeURIComponent(fileName)}`;
                    docLink.className = 'document-link';
                    docLink.target = '_blank';
                    docLink.innerHTML = `<i class="fas fa-file me-1"></i>View Document ${index + 1}`;
                    documentsContainer.appendChild(docLink);
                });
            } else {
                documentsContainer.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle"></i> No supporting documents</span>';
            }

            // Status
            const statusBadges = {
                'pending': 'bg-warning',
                'approved': 'bg-success',
                'rejected': 'bg-danger'
            };
            const statusTexts = {
                'pending': 'Pending',
                'approved': 'Approved',
                'rejected': 'Rejected'
            };
            const statusBadge = statusBadges[req.status] || 'bg-secondary';
            const statusText = statusTexts[req.status] || req.status.charAt(0).toUpperCase() + req.status.slice(1);
            document.getElementById('detailStatus').innerHTML = `<span class="badge status-badge ${statusBadge}">${statusText}</span>`;

            // Request Information
            const requestedAt = new Date(req.created_at);
            document.getElementById('detailRequestedAt').textContent = requestedAt.toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });

            // Review Information
            const reviewSection = document.getElementById('detailReviewSection');
            if (req.reviewed_at) {
                reviewSection.style.display = 'block';
                document.getElementById('detailReviewedBy').textContent = reviewerName || 'Unknown';
                const reviewedAt = new Date(req.reviewed_at);
                document.getElementById('detailReviewedAt').textContent = reviewedAt.toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });
                document.getElementById('detailReviewNotes').textContent = req.review_notes || 'No notes provided';
            } else {
                reviewSection.style.display = 'none';
            }

            // Show action buttons / dean note for pending requests
            const deanNote = document.getElementById('detailDeanNote');
            const actionButtons = document.getElementById('detailActionButtons');
            if (req.status === 'pending') {
                deanNote.style.display = 'block';
                if (canApproveReject && actionButtons) {
                    actionButtons.innerHTML = '<button onclick="approveRequest(' + req.id + '); bootstrap.Modal.getInstance(document.getElementById(\'detailsModal\')).hide();" class="btn btn-success"><i class="fas fa-check"></i> Approve</button><button onclick="rejectRequest(' + req.id + '); bootstrap.Modal.getInstance(document.getElementById(\'detailsModal\')).hide();" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>';
                } else if (actionButtons) {
                    actionButtons.innerHTML = '';
                }
            } else {
                deanNote.style.display = 'none';
                if (actionButtons) actionButtons.innerHTML = '';
            }

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        }
    </script>
</body>
</html>

