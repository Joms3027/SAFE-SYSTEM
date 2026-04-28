<?php
/**
 * Faculty Scope Activity Counts API
 * Returns real-time counts for "People in My Scope" sidebar badges.
 * Used for: Requesting Open Pardon, Official Time Requests, DTR Submissions.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$result = [
    'success' => true,
    'counts' => [
        'pardon_letters' => 0,
        'official_time' => 0,
        'dtr_submissions' => 0,
        'tarf_supervisor' => 0,
        'tarf_endorser' => 0,
        'tarf_fund' => 0,
        'tarf_president' => 0,
    ]
];

$userProfile = null;
$stmt = $db->prepare("SELECT fp.designation, fp.department, fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';
$deanDepartment = trim($userProfile['department'] ?? '');
$hasScopeAssignments = function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($_SESSION['user_id'], $db);
$currentUserEmployeeId = trim($userProfile['employee_id'] ?? '');

$canAccessScope = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
$isTarfEndorser = tarf_is_endorser_target_user((int) ($_SESSION['user_id'] ?? 0), $db);
$isTarfFundActor = function_exists('tarf_user_holds_fund_availability_designation')
    && tarf_user_holds_fund_availability_designation((int) ($_SESSION['user_id'] ?? 0), $db);
$isTarfPresidentViewer = function_exists('tarf_is_president_key_official_viewer')
    && tarf_is_president_key_official_viewer((int) ($_SESSION['user_id'] ?? 0));
if (!$canAccessScope && !$isTarfEndorser && !$isTarfFundActor && !$isTarfPresidentViewer) {
    echo json_encode($result);
    exit;
}

try {
    // TARF — supervisor queue (pardon opener scope)
    if ($hasScopeAssignments) {
        $tarfTbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
        $tarfCol = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote('status'));
        if ($tarfTbl && $tarfTbl->rowCount() > 0 && $tarfCol && $tarfCol->rowCount() > 0) {
            $scopeIds = function_exists('getEmployeeIdsInScope') ? getEmployeeIdsInScope($_SESSION['user_id'], $db) : [];
            if (!empty($scopeIds)) {
                $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM tarf_requests WHERE employee_id IN ($placeholders)
                     AND status IN ('pending_joint','pending_supervisor','pending_endorser')
                     AND supervisor_endorsed_at IS NULL"
                );
                $stmt->execute($scopeIds);
                $result['counts']['tarf_supervisor'] = (int) $stmt->fetchColumn();
            }
        }
    }

    // TARF — applicable endorser inbox
    if ($isTarfEndorser) {
        $tarfTbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
        if ($tarfTbl && $tarfTbl->rowCount() > 0) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM tarf_requests WHERE status IN ('pending_joint','pending_supervisor','pending_endorser')
                 AND endorser_endorsed_at IS NULL AND endorser_target_user_id = ?"
            );
            $stmt->execute([(int) $_SESSION['user_id']]);
            $result['counts']['tarf_endorser'] = (int) $stmt->fetchColumn();
        }
    }

    if ($isTarfFundActor) {
        $tarfTbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
        $fc = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote('fund_availability_target_user_id'));
        if ($tarfTbl && $tarfTbl->rowCount() > 0 && $fc && $fc->rowCount() > 0) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM tarf_requests WHERE status IN ('pending_joint','pending_supervisor','pending_endorser')
                 AND fund_availability_target_user_id = ?
                 AND fund_availability_endorsed_at IS NULL"
            );
            $stmt->execute([(int) $_SESSION['user_id']]);
            $result['counts']['tarf_fund'] = (int) $stmt->fetchColumn();
        }
    }

    // TARF — President final approval inbox
    if ($isTarfPresidentViewer) {
        $tarfTbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
        if ($tarfTbl && $tarfTbl->rowCount() > 0) {
            $stmt = $db->query("SELECT COUNT(*) FROM tarf_requests WHERE status = 'pending_president'");
            $result['counts']['tarf_president'] = (int) $stmt->fetchColumn();
        }
    }

    // 1. Pardon Request Letters (pending/acknowledged - not opened/rejected/closed)
    // Only for pardon openers (not dean - dean doesn't see this menu item)
    if ($hasScopeAssignments) {
        $employeeIdsInScope = function_exists('getEmployeeIdsInScope') ? getEmployeeIdsInScope($_SESSION['user_id'], $db) : [];
        if (!empty($employeeIdsInScope)) {
            $tbl = $db->query("SHOW TABLES LIKE 'pardon_request_letters'");
            if ($tbl && $tbl->rowCount() > 0) {
                $placeholders = implode(',', array_fill(0, count($employeeIdsInScope), '?'));
                $stmt = $db->prepare("SELECT COUNT(*) FROM pardon_request_letters 
                    WHERE employee_id IN ($placeholders) 
                    AND (status IS NULL OR status NOT IN ('opened', 'rejected', 'closed'))");
                $stmt->execute($employeeIdsInScope);
                $result['counts']['pardon_letters'] = (int) $stmt->fetchColumn();
            }
        }
    }

    // 2. Official Time Requests (pending_dean)
    if ($isDean && $deanDepartment !== '') {
        $params = [$deanDepartment];
        if ($currentUserEmployeeId !== '') {
            $params[] = $currentUserEmployeeId;
        }
        $sql = "SELECT COUNT(DISTINCT otr.employee_id) FROM official_time_requests otr
            JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
            WHERE otr.status = 'pending_dean' AND LOWER(TRIM(fp.department)) = LOWER(?)";
        if ($currentUserEmployeeId !== '') {
            $sql .= " AND fp.employee_id != ?";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result['counts']['official_time'] = (int) $stmt->fetchColumn();
    } elseif ($hasScopeAssignments) {
        $scopeEmployeeIds = function_exists('getEmployeeIdsInScope') ? getEmployeeIdsInScope($_SESSION['user_id'], $db) : [];
        if ($currentUserEmployeeId !== '' && in_array($currentUserEmployeeId, $scopeEmployeeIds)) {
            $scopeEmployeeIds = array_values(array_diff($scopeEmployeeIds, [$currentUserEmployeeId]));
        }
        if (!empty($scopeEmployeeIds)) {
            $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
            $stmt = $db->prepare("SELECT COUNT(DISTINCT employee_id) FROM official_time_requests 
                WHERE status = 'pending_dean' AND employee_id IN ($placeholders)");
            $stmt->execute($scopeEmployeeIds);
            $result['counts']['official_time'] = (int) $stmt->fetchColumn();
        }
    }

    // 3. DTR Submissions (unverified in current month)
    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
    $colCheck = $tableCheck && $tableCheck->rowCount() > 0
        ? $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'")
        : null;
    $hasVerifiedColumn = $colCheck && $colCheck->rowCount() > 0;

    if ($hasVerifiedColumn) {
        $year = (int) date('Y');
        $month = (int) date('n');
        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $dateTo = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

        if ($isDean && $deanDepartment !== '') {
            $params = [$dateFrom, $dateTo, $deanDepartment];
            if ($currentUserEmployeeId !== '') {
                $params[] = $currentUserEmployeeId;
            }
            $sql = "SELECT COUNT(DISTINCT ds.user_id) FROM dtr_daily_submissions ds
                INNER JOIN users u ON ds.user_id = u.id
                INNER JOIN faculty_profiles fp ON fp.user_id = u.id
                WHERE ds.log_date >= ? AND ds.log_date <= ?
                AND u.user_type = 'faculty' AND LOWER(TRIM(fp.department)) = LOWER(?)
                AND ds.dean_verified_at IS NULL";
            if ($currentUserEmployeeId !== '') {
                $sql .= " AND fp.employee_id != ?";
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result['counts']['dtr_submissions'] = (int) $stmt->fetchColumn();
        } elseif ($hasScopeAssignments) {
            $scopeEmployeeIds = function_exists('getEmployeeIdsInScope') ? getEmployeeIdsInScope($_SESSION['user_id'], $db) : [];
            if ($currentUserEmployeeId !== '' && in_array($currentUserEmployeeId, $scopeEmployeeIds)) {
                $scopeEmployeeIds = array_values(array_diff($scopeEmployeeIds, [$currentUserEmployeeId]));
            }
            if (!empty($scopeEmployeeIds)) {
                $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
                $params = array_merge([$dateFrom, $dateTo], $scopeEmployeeIds);
                $stmt = $db->prepare("SELECT COUNT(DISTINCT ds.user_id) FROM dtr_daily_submissions ds
                    INNER JOIN faculty_profiles fp ON fp.user_id = ds.user_id
                    WHERE ds.log_date >= ? AND ds.log_date <= ?
                    AND fp.employee_id IN ($placeholders)
                    AND ds.dean_verified_at IS NULL");
                $stmt->execute($params);
                $result['counts']['dtr_submissions'] = (int) $stmt->fetchColumn();
            }
        }
    }
} catch (Exception $e) {
    error_log('faculty_scope_activity_counts_api: ' . $e->getMessage());
}

echo json_encode($result);
