<?php
/**
 * Announcements from My Supervisor - For employees to view announcements from their pardon opener(s).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAuth();

$database = Database::getInstance();
$db = $database->getConnection();

// Get current user's employee_id, employment_type, user_type, and employment_status
$employeeId = null;
$userEmploymentType = '';  // faculty or staff
$userEmploymentStatus = '';
$stmt = $db->prepare("SELECT fp.employee_id, fp.employment_type, fp.employment_status, fp.department, fp.designation FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row['employee_id'])) {
    $employeeId = trim($row['employee_id']);
}
$employmentType = strtolower(trim(($row ?? [])['employment_type'] ?? ''));
$userType = $_SESSION['user_type'] ?? '';
if ($employmentType === 'faculty' || $userType === 'faculty') {
    $userEmploymentType = 'faculty';
}
if ($employmentType === 'staff' || $userType === 'staff') {
    $userEmploymentType = 'staff';
}
$userEmploymentStatus = trim(($row ?? [])['employment_status'] ?? '');

// Get supervisor (pardon opener) user IDs for this employee
$supervisorIds = [];
// Supervisor sees their own announcements (created by them)
if (function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
    $supervisorIds[] = (int)$_SESSION['user_id'];
}
if (function_exists('getOpenerUserIdsForEmployee') && $employeeId) {
    $openerIds = getOpenerUserIdsForEmployee($employeeId, $db);
    foreach ($openerIds as $oid) {
        if (!in_array($oid, $supervisorIds)) $supervisorIds[] = $oid;
    }
}
// Fallback 1: if no employee_id but has faculty_profile with dept/designation, find openers by direct lookup
if (empty($supervisorIds) && $row) {
    $empDept = trim($row['department'] ?? '');
    $empDesig = trim($row['designation'] ?? '');
    if ($empDept !== '' || $empDesig !== '') {
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
            if ($tbl && $tbl->rowCount() > 0) {
                $conds = [];
                $params = [];
                if ($empDept !== '') {
                    $conds[] = "(poa.scope_type = 'department' AND LOWER(TRIM(poa.scope_value)) = LOWER(?))";
                    $params[] = $empDept;
                }
                if ($empDesig !== '') {
                    $conds[] = "(poa.scope_type = 'designation' AND TRIM(poa.scope_value) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(?))";
                    $params[] = $empDesig;
                }
                if (!empty($conds)) {
                    $params[] = (int)($_SESSION['user_id'] ?? 0);
                    $stmt = $db->prepare("SELECT DISTINCT poa.user_id FROM pardon_opener_assignments poa WHERE (" . implode(' OR ', $conds) . ") AND poa.user_id != ?");
                    $stmt->execute($params);
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $supervisorIds[] = (int)$r['user_id'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Supervisor scope fallback error: " . $e->getMessage());
        }
    }
}
// Fallback 2: reverse lookup - get all supervisors with announcements, check if employee is in their scope (uses same logic as notification sending)
if (empty($supervisorIds) && $employeeId && function_exists('getEmployeeIdsInScope')) {
    try {
        $stmt = $db->query("SELECT DISTINCT supervisor_id FROM supervisor_announcements WHERE is_active = 1");
        if ($stmt) {
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $supId = (int)$r['supervisor_id'];
                $scopeEmployeeIds = getEmployeeIdsInScope($supId, $db);
                if (in_array($employeeId, $scopeEmployeeIds)) {
                    $supervisorIds[] = $supId;
                }
            }
            $supervisorIds = array_values(array_unique($supervisorIds));
        }
    } catch (Exception $e) {
        error_log("Supervisor scope reverse lookup error: " . $e->getMessage());
    }
}

// Fetch announcements from those supervisors
$announcements = [];
try {
    if (!empty($supervisorIds)) {
        $hasTargetAudience = false;
        $checkCol = @$db->query("SHOW COLUMNS FROM supervisor_announcements LIKE 'target_audience'");
        if ($checkCol && $checkCol->rowCount() > 0) $hasTargetAudience = true;
        $cols = $hasTargetAudience
            ? 'sa.id, sa.supervisor_id, sa.title, sa.content, sa.priority, sa.target_audience, sa.created_at, sa.expires_at'
            : 'sa.id, sa.supervisor_id, sa.title, sa.content, sa.priority, sa.created_at, sa.expires_at';
        $placeholders = implode(',', array_fill(0, count($supervisorIds), '?'));
        $stmt = $db->prepare("
            SELECT $cols, u.first_name, u.last_name
            FROM supervisor_announcements sa
            JOIN users u ON sa.supervisor_id = u.id
            WHERE sa.supervisor_id IN ($placeholders) AND sa.is_active = 1
              AND (sa.expires_at IS NULL OR sa.expires_at >= NOW())
            ORDER BY sa.created_at DESC
        ");
        $stmt->execute($supervisorIds);
        $allAnnouncements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allAnnouncements as &$a) { if (!isset($a['target_audience'])) $a['target_audience'] = 'all'; }
        unset($a);
        // Filter by target_audience: all | faculty|STATUS,staff|STATUS (multiple - match any)
        // Creator (supervisor) always sees their own announcements regardless of target_audience
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        foreach ($allAnnouncements as $a) {
            $supervisorId = (int)($a['supervisor_id'] ?? 0);
            if ($supervisorId === $currentUserId) {
                $announcements[] = $a;
                continue;
            }
            $target = trim($a['target_audience'] ?? 'all');
            if ($target === 'all' || $target === '') {
                $announcements[] = $a;
            } else {
                $selections = array_map('trim', explode(',', $target));
                $matched = false;
                foreach ($selections as $sel) {
                    $parts = explode('|', $sel, 2);
                    if (count($parts) === 2) {
                        $targetType = $parts[0];
                        $targetStatus = trim($parts[1]);
                        $typeMatch = ($targetType === 'faculty' && $userEmploymentType === 'faculty') || ($targetType === 'staff' && $userEmploymentType === 'staff');
                        $statusMatch = (strcasecmp($userEmploymentStatus, $targetStatus) === 0)
                            || ($userEmploymentStatus === '');
                        if ($typeMatch && $statusMatch) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if ($matched || $userEmploymentType === '') {
                    $announcements[] = $a;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Supervisor scope announcements load error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/navigation.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements from Supervisor - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
</head>
<body class="layout-faculty">
    <?php include_navigation(); ?>
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title"><i class="fas fa-bullhorn me-2"></i>Announcements from My Supervisor</div>
                    <p class="page-subtitle text-muted">Updates and announcements from your immediate supervisor.</p>
                </div>

                <?php displayMessage(); ?>

                <?php if (empty($announcements)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-bullhorn fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No announcements at the moment</h5>
                        <p class="text-muted">Your supervisor will post updates here. You'll also receive email notifications when new announcements are posted.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($announcements as $a): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card h-100 announcement-card <?php echo $a['priority'] === 'urgent' ? 'border-danger' : ($a['priority'] === 'high' ? 'border-warning' : ''); ?>">
                                    <div class="card-header bg-light">
                                        <h6 class="card-title mb-0"><?php echo htmlspecialchars($a['title']); ?></h6>
                                        <?php if ($a['priority'] === 'urgent'): ?>
                                            <span class="badge bg-danger mt-1">Urgent</span>
                                        <?php elseif ($a['priority'] === 'high'): ?>
                                            <span class="badge bg-warning text-dark mt-1">High</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <p class="card-text"><?php echo nl2br(htmlspecialchars(substr($a['content'], 0, 150)) . (strlen($a['content']) > 150 ? '...' : '')); ?></p>
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))); ?>
                                            <br><i class="far fa-clock me-1"></i><?php echo formatDate($a['created_at'], 'M j, Y g:i A'); ?>
                                        </small>
                                    </div>
                                    <div class="card-footer bg-transparent">
                                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $a['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="modal fade" id="viewModal<?php echo $a['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title"><?php echo htmlspecialchars($a['title']); ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3"><?php echo nl2br(htmlspecialchars($a['content'])); ?></div>
                                            <hr>
                                            <small class="text-muted">
                                                <i class="fas fa-user me-1"></i>From: <?php echo htmlspecialchars(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))); ?><br>
                                                <i class="far fa-clock me-1"></i>Posted: <?php echo formatDate($a['created_at'], 'M j, Y g:i A'); ?>
                                                <?php if ($a['expires_at']): ?><br><i class="fas fa-hourglass-end me-1"></i>Expires: <?php echo formatDate($a['expires_at'], 'M j, Y'); ?><?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
</body>
</html>
