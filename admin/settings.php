<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/auth.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$auth = new Auth();

$message = '';

// Handle form submissions via user_actions.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'user_actions.php';
    exit();
}

// Get admin users with pagination (5 per page)
// All admins and super_admin users are visible to both admin and super_admin viewers
$adminTypeFilter = "user_type IN ('admin', 'super_admin')";
$countSql = "SELECT COUNT(*) FROM users WHERE $adminTypeFilter";
$pUsers = getPaginationParams($db, $countSql, [], 5);

$stmt = $db->prepare("SELECT id, first_name, last_name, email, is_active, is_verified, created_at, user_type FROM users WHERE $adminTypeFilter ORDER BY user_type DESC, created_at DESC LIMIT {$pUsers['limit']} OFFSET {$pUsers['offset']}");
$stmt->execute();
$adminUsers = $stmt->fetchAll();

// Get system settings
$settings = [
    'site_name' => SITE_NAME,
    'max_file_size' => MAX_FILE_SIZE,
    'allowed_file_types' => implode(', ', ALLOWED_FILE_TYPES)
];

// Get pardon weekly limit setting (super admin only)
$pardonWeeklyLimit = 3;
if (isSuperAdmin()) {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'pardon_weekly_limit' LIMIT 1");
    $stmt->execute();
    $pardonLimitSetting = $stmt->fetch(PDO::FETCH_ASSOC);
    $pardonWeeklyLimit = $pardonLimitSetting ? intval($pardonLimitSetting['setting_value']) : 3;
}

// Fetch master lists for departments and employment statuses with pagination (5 per page)
$perPage = 5;

$deptPage = isset($_GET['dept_page']) ? max(1, intval($_GET['dept_page'])) : 1;
$empPage = isset($_GET['emp_page']) ? max(1, intval($_GET['emp_page'])) : 1;

// Departments
$countDept = $db->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$deptTotalPages = max(1, ceil($countDept / $perPage));
$deptOffset = ($deptPage - 1) * $perPage;
$deptStmt = $db->prepare("SELECT id, name FROM departments ORDER BY name LIMIT ? OFFSET ?");
$deptStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$deptStmt->bindValue(2, $deptOffset, PDO::PARAM_INT);
$deptStmt->execute();
$departments = $deptStmt->fetchAll();

// Employment Statuses
$countEmp = $db->query("SELECT COUNT(*) FROM employment_statuses")->fetchColumn();
$empTotalPages = max(1, ceil($countEmp / $perPage));
$empOffset = ($empPage - 1) * $perPage;
$empStmt = $db->prepare("SELECT id, name FROM employment_statuses ORDER BY name LIMIT ? OFFSET ?");
$empStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$empStmt->bindValue(2, $empOffset, PDO::PARAM_INT);
$empStmt->execute();
$employmentStatuses = $empStmt->fetchAll();

// Campuses
$campusPage = isset($_GET['campus_page']) ? max(1, intval($_GET['campus_page'])) : 1;
$countCampus = $db->query("SELECT COUNT(*) FROM campuses")->fetchColumn();
$campusTotalPages = max(1, ceil($countCampus / $perPage));
$campusOffset = ($campusPage - 1) * $perPage;
$campusStmt = $db->prepare("SELECT id, name FROM campuses ORDER BY name LIMIT ? OFFSET ?");
$campusStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$campusStmt->bindValue(2, $campusOffset, PDO::PARAM_INT);
$campusStmt->execute();
$campuses = $campusStmt->fetchAll();

// Designations
$designationPage = isset($_GET['designation_page']) ? max(1, intval($_GET['designation_page'])) : 1;
$countDesignation = $db->query("SELECT COUNT(*) FROM designations")->fetchColumn();
$designationTotalPages = max(1, ceil($countDesignation / $perPage));
$designationOffset = ($designationPage - 1) * $perPage;
$designationStmt = $db->prepare("SELECT id, name FROM designations ORDER BY name LIMIT ? OFFSET ?");
$designationStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$designationStmt->bindValue(2, $designationOffset, PDO::PARAM_INT);
$designationStmt->execute();
$designations = $designationStmt->fetchAll();

// Key Officials (table created by migration 20260306_create_key_officials_table.sql)
$keyOfficials = [];
$keyOfficialsPage = 1;
$keyOfficialsTotalPages = 1;
try {
    $countKeyOfficials = $db->query("SELECT COUNT(*) FROM key_officials")->fetchColumn();
    $keyOfficialsPage = isset($_GET['key_officials_page']) ? max(1, intval($_GET['key_officials_page'])) : 1;
    $keyOfficialsTotalPages = max(1, ceil($countKeyOfficials / $perPage));
    $keyOfficialsOffset = ($keyOfficialsPage - 1) * $perPage;
    $keyOfficialsStmt = $db->prepare("SELECT id, name FROM key_officials ORDER BY name LIMIT ? OFFSET ?");
    $keyOfficialsStmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $keyOfficialsStmt->bindValue(2, $keyOfficialsOffset, PDO::PARAM_INT);
    $keyOfficialsStmt->execute();
    $keyOfficials = $keyOfficialsStmt->fetchAll();
} catch (Exception $e) {
    // Table may not exist yet - run migration 20260306_create_key_officials_table.sql
}

// All departments and designations for pardon opener scope dropdown (no pagination)
$allDepartments = [];
$allDesignations = [];
try {
    $allDepartments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
    $allDesignations = $db->query("SELECT id, name FROM designations ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// Pardon opener assignments (Super Admin only) - table from migration 20260312
$pardonOpeners = [];
$pardonOpenerUsers = [];
$hasPardonOpenerTable = false;
try {
    $tblCheck = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
    $hasPardonOpenerTable = $tblCheck && $tblCheck->rowCount() > 0;
    if ($hasPardonOpenerTable && isSuperAdmin()) {
        $stmt = $db->query("SELECT poa.id, poa.user_id, poa.scope_type, poa.scope_value,
            u.first_name, u.last_name, u.user_type
            FROM pardon_opener_assignments poa
            JOIN users u ON poa.user_id = u.id
            ORDER BY u.last_name, u.first_name, poa.scope_type, poa.scope_value");
        $pardonOpeners = $stmt->fetchAll();
        // Group by user for compact table view (one row per person)
        $pardonOpenersGrouped = [];
        foreach ($pardonOpeners as $po) {
            $uid = $po['user_id'];
            if (!isset($pardonOpenersGrouped[$uid])) {
                $pardonOpenersGrouped[$uid] = [
                    'first_name' => $po['first_name'],
                    'last_name' => $po['last_name'],
                    'user_type' => $po['user_type'],
                    'assignments' => []
                ];
            }
            $pardonOpenersGrouped[$uid]['assignments'][] = $po;
        }
        // Users who can be assigned: faculty, admin, super_admin (active)
        $stmtUsers = $db->query("SELECT u.id, u.first_name, u.last_name, u.email, u.user_type,
            fp.department, fp.designation
            FROM users u
            LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
            WHERE u.user_type IN ('faculty', 'staff', 'admin', 'super_admin') AND u.is_active = 1
            ORDER BY u.user_type, u.last_name, u.first_name");
        $pardonOpenerUsers = $stmtUsers->fetchAll();
    }
} catch (Exception $e) {
    // Table may not exist
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('System Settings', 'Manage system settings and master lists');
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
                    'System Settings',
                    'Manage password, master lists, pardon limits, and admin users.',
                    'fas fa-cog',
                    [
                        ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'fas fa-th-large'],
                        ['label' => 'Settings', 'icon' => 'fas fa-cog']
                    ]
                );
                ?>

                <?php displayMessage(); ?>

                <nav class="settings-quick-nav" aria-label="Settings sections">
                    <div class="nav-scroll">
                        <span class="text-muted me-2 small">Jump to:</span>
                        <a href="#section-password"><i class="fas fa-key"></i> Change Password</a>
                        <a href="#section-master-lists"><i class="fas fa-list"></i> Master Lists</a>
                        <?php if (isSuperAdmin()): ?><a href="#section-pardon"><i class="fas fa-gavel"></i> Pardon Settings</a><?php endif; ?>
                        <?php if (isSuperAdmin() && $hasPardonOpenerTable): ?><a href="#section-pardon-openers"><i class="fas fa-user-shield"></i> Pardon Openers</a><?php endif; ?>
                        <a href="#section-admin-users"><i class="fas fa-users-cog"></i> Admin Users</a>
                        <a href="#section-activity"><i class="fas fa-history"></i> Activity</a>
                    </div>
                </nav>

                <div class="row">
                    <!-- Change Password Section for Current Admin -->
                    <div class="col-12 col-lg-6 mb-4 settings-section" id="section-password">
                        <div class="card h-100">
                            <div class="card-header">
                                <span class="section-icon"><i class="fas fa-key"></i></span>
                                <h5 class="mb-0">Change Password</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Update your account password. Use a strong password with mixed characters.</p>
                                <form method="POST" action="user_actions.php" class="row g-3">
                                    <input type="hidden" name="action" value="change_password">
                                    <?php addFormToken(); ?>
                                    
                                    <div class="col-md-12">
                                        <label for="current_password" class="form-label">Current Password *</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                                            <button type="button" class="btn btn-outline-secondary" id="toggleCurrentPassword" title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="new_password" class="form-label">New Password *</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                            <button type="button" class="btn btn-outline-secondary" id="toggleNewPassword" title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>Minimum 8 characters. Must include uppercase, lowercase, number, and special character.
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                                            <button type="button" class="btn btn-outline-secondary" id="toggleConfirmPassword" title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">Re-enter your new password to confirm.</div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-key me-2"></i>Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Master lists: compact view with modal manager -->
                    <div class="col-12 mb-4 settings-section" id="section-master-lists">
                        <div class="card">
                            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="section-icon"><i class="fas fa-list"></i></span>
                                    <h5 class="mb-0">Master Lists</h5>
                                </div>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#masterListsModal">
                                    <i class="fas fa-edit me-1"></i> Manage Master Lists
                                </button>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Preview of departments, statuses, campuses, designations, and key officials. Click <strong>Manage Master Lists</strong> to add, edit, or delete items.</p>
                                <div class="row master-list-preview g-2">
                                    <div class="col-sm-6 col-md-3 mb-2">
                                        <div class="list-block h-100">
                                            <h6><i class="fas fa-building" aria-hidden="true"></i> Departments</h6>
                                        <?php if (empty($departments)): ?>
                                            <p class="text-muted small mb-0">None yet. Click Manage to add.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($departments, 0, 6) as $d): ?>
                                                <span class="badge bg-light text-dark me-1 mb-1"><?php echo htmlspecialchars($d['name']); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($departments) > 6): ?>
                                                <span class="text-muted small">+<?php echo count($departments) - 6; ?> more</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-md-3 mb-2">
                                        <div class="list-block h-100">
                                            <h6><i class="fas fa-briefcase"></i> Employment Status</h6>
                                        <?php if (empty($employmentStatuses)): ?>
                                            <p class="text-muted small mb-0">None yet. Click Manage to add.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($employmentStatuses, 0, 6) as $e): ?>
                                                <span class="badge bg-light text-dark me-1 mb-1"><?php echo htmlspecialchars($e['name']); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($employmentStatuses) > 6): ?>
                                                <span class="text-muted small">+<?php echo count($employmentStatuses) - 6; ?> more</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-md-3 mb-2">
                                        <div class="list-block h-100">
                                            <h6><i class="fas fa-university"></i> Campuses</h6>
                                        <?php if (empty($campuses)): ?>
                                            <p class="text-muted small mb-0">None yet. Click Manage to add.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($campuses, 0, 6) as $c): ?>
                                                <span class="badge bg-light text-dark me-1 mb-1"><?php echo htmlspecialchars($c['name']); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($campuses) > 6): ?>
                                                <span class="text-muted small">+<?php echo count($campuses) - 6; ?> more</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-md-3 mb-2">
                                        <div class="list-block h-100">
                                            <h6><i class="fas fa-id-badge"></i> Designations</h6>
                                        <?php if (empty($designations)): ?>
                                            <p class="text-muted small mb-0">None yet. Click Manage to add.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($designations, 0, 6) as $d): ?>
                                                <span class="badge bg-light text-dark me-1 mb-1"><?php echo htmlspecialchars($d['name']); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($designations) > 6): ?>
                                                <span class="text-muted small">+<?php echo count($designations) - 6; ?> more</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-md-3 mb-2">
                                        <div class="list-block h-100">
                                            <h6><i class="fas fa-user-tie"></i> Key Officials</h6>
                                        <?php if (empty($keyOfficials)): ?>
                                            <p class="text-muted small mb-0">None yet. Click Manage to add.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($keyOfficials, 0, 6) as $k): ?>
                                                <span class="badge bg-light text-dark me-1 mb-1"><?php echo htmlspecialchars($k['name']); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($keyOfficials) > 6): ?>
                                                <span class="text-muted small">+<?php echo count($keyOfficials) - 6; ?> more</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pardon Request Settings (Super Admin only) -->
                    <?php if (isSuperAdmin()): ?>
                    <div class="col-12 col-lg-6 mb-4 settings-section" id="section-pardon">
                        <div class="card h-100">
                            <div class="card-header">
                                <span class="section-icon"><i class="fas fa-gavel"></i></span>
                                <h5 class="mb-0">Pardon Request Settings</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Set how many pardon requests each employee can submit per week.</p>
                                <form method="POST" action="user_actions.php" class="row g-3">
                                    <input type="hidden" name="action" value="update_pardon_limit">
                                    <?php addFormToken(); ?>
                                    
                                    <div class="col-md-6">
                                        <label for="pardon_weekly_limit" class="form-label">Weekly Pardon Limit *</label>
                                        <input type="number" class="form-control" id="pardon_weekly_limit" name="pardon_weekly_limit" 
                                               value="<?php echo htmlspecialchars($pardonWeeklyLimit); ?>" 
                                               min="1" max="100" required>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>Enter the maximum number of pardon requests allowed per employee per week. Once an employee reaches this limit, they must wait until the next week to submit new pardon requests.
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save Settings
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Pardon Openers (Super Admin only) - Who can open pardon for their employees -->
                    <?php if (isSuperAdmin() && $hasPardonOpenerTable): ?>
                    <div class="col-12 mb-3 settings-section" id="section-pardon-openers">
                        <div class="card pardon-openers-card">
                            <div class="card-header py-2">
                                <span class="section-icon"><i class="fas fa-user-shield"></i></span>
                                <h5 class="mb-0">Pardon Openers</h5>
                            </div>
                            <div class="card-body py-2">
                                <form method="POST" action="user_actions.php" id="pardonOpenerForm" class="mb-2">
                                    <input type="hidden" name="action" value="add_pardon_opener">
                                    <?php addFormToken(); ?>
                                    <div class="row g-2 align-items-end">
                                        <div class="col-12 col-md" style="min-width: 0;">
                                            <label for="po_user_search" class="form-label small mb-0">Person</label>
                                            <input type="text" class="form-control form-control-sm" id="po_user_search" placeholder="Search..." autocomplete="off" list="po-person-list">
                                            <input type="hidden" name="user_id" id="po_user_id" value="">
                                            <datalist id="po-person-list">
                                                <?php foreach ($pardonOpenerUsers as $u): ?>
                                                    <?php $label = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?>
                                                    <?php if (trim($u['department'] ?? '') !== '') $label .= ', ' . trim($u['department']); ?>
                                                    <?php if (trim($u['designation'] ?? '') !== '') $label .= ' (' . trim($u['designation']) . ')'; ?>
                                                    <?php $label .= ' [' . ($u['user_type'] ?? '') . ']'; ?>
                                                    <option value="<?php echo htmlspecialchars($label); ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <label for="po_scope_type" class="form-label small mb-0">Type</label>
                                            <select class="form-select form-select-sm" id="po_scope_type" name="scope_type" required>
                                                <option value="department">Dept</option>
                                                <option value="designation">Desig</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md" style="min-width: 0;">
                                            <label for="po_scope_input" class="form-label small mb-0">Scope</label>
                                            <div class="po-scope-combobox border rounded px-2 py-1 bg-light d-flex flex-wrap align-items-center gap-1" style="min-height: 38px;">
                                                <div class="d-flex flex-wrap gap-1 flex-grow-1" id="po_scope_tags"></div>
                                                <input type="text" class="form-control form-control-sm border-0 bg-transparent flex-grow-1" id="po_scope_input" placeholder="Type or pick from list..." autocomplete="off" list="po_scope_datalist" style="min-width: 120px;">
                                                <datalist id="po_scope_datalist"></datalist>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-auto">
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add</button>
                                        </div>
                                    </div>
                                </form>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="py-1">Person</th>
                                                <th class="py-1">Scopes</th>
                                                <th class="py-1 text-end" style="width: 90px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pardonOpenersGrouped as $userId => $person): ?>
                                            <tr>
                                                <td class="align-top py-1" style="width: 1%; white-space: nowrap;">
                                                    <span class="fw-semibold small"><?php echo htmlspecialchars(trim($person['first_name'] . ' ' . $person['last_name'])); ?></span>
                                                    <span class="badge bg-light text-dark ms-1" style="font-size: 0.65rem;"><?php echo htmlspecialchars($person['user_type'] ?? ''); ?></span>
                                                </td>
                                                <td class="py-1">
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php foreach ($person['assignments'] as $a): ?>
                                                        <span class="badge <?php echo $a['scope_type'] === 'department' ? 'bg-info' : 'bg-secondary'; ?> d-inline-flex align-items-center gap-1 po-scope-badge" style="font-size: 0.7rem;">
                                                            <?php echo $a['scope_type'] === 'department' ? 'Dept' : 'Desig'; ?>: <?php echo htmlspecialchars($a['scope_value']); ?>
                                                            <form method="POST" action="user_actions.php" class="d-inline po-scope-remove" onsubmit="return confirm('Remove?');">
                                                                <input type="hidden" name="action" value="delete_pardon_opener">
                                                                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                                                <?php addFormToken(); ?>
                                                                <button type="submit" class="btn btn-link p-0 border-0 text-white" style="font-size: 0.6rem; opacity: 0.8;" title="Remove"><i class="fas fa-times"></i></button>
                                                            </form>
                                                        </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                                <td class="py-1 text-end align-top">
                                                    <form method="POST" action="user_actions.php" class="d-inline" onsubmit="return confirm('Remove all assignments for this person?');">
                                                        <input type="hidden" name="action" value="delete_all_pardon_opener">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                                                        <?php addFormToken(); ?>
                                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1 small" title="Delete all"><i class="fas fa-trash-alt me-1"></i>Delete all</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($pardonOpenersGrouped)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-3 small text-muted">
                                                    <i class="fas fa-inbox d-block mb-1"></i>No assignments yet. Add one above.
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="accordion mt-2" id="pardonOpenersHelp">
                                    <div class="accordion-item border-0">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-1 px-2 bg-light small" type="button" data-bs-toggle="collapse" data-bs-target="#pardonOpenersHelpBody" aria-expanded="false">
                                                <i class="fas fa-info-circle me-1 text-primary"></i>Help
                                            </button>
                                        </h2>
                                        <div id="pardonOpenersHelpBody" class="accordion-collapse collapse">
                                            <div class="accordion-body py-1 px-2 small">
                                                <p class="mb-1"><strong>Department:</strong> Person can open pardon for employees in that department (e.g. CAS faculty).</p>
                                                <p class="mb-1"><strong>Designation:</strong> Person can open pardon for employees with that designation (e.g. Deans, NSTP Director).</p>
                                                <p class="mb-0">Assigned employees will see <strong>My Assigned Employees</strong> in their sidebar to view and open pardon for people in their scope.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Admin User Management -->
                    <div class="col-12 mb-4 settings-section" id="section-admin-users">
                        <div class="card">
                            <div class="card-header">
                                <span class="section-icon"><i class="fas fa-users-cog"></i></span>
                                <h5 class="mb-0">Admin User Management</h5>
                            </div>
                            <div class="card-body">
                                <!-- Create New Admin Form (Super Admin only) -->
                                <?php if (isSuperAdmin()): ?>
                                <div class="mb-4 p-3 bg-light rounded">
                                    <h6 class="text-primary mb-3"><i class="fas fa-user-plus me-1"></i>Create New Admin User</h6>
                                    <form method="POST" class="row g-3">
                                        <input type="hidden" name="action" value="create_admin">
                                        
                                        <div class="col-md-6">
                                            <label for="email" class="form-label">Email Address *</label>
                                            <input type="email" class="form-control" id="email" name="email" required>
                                            <div class="form-text">Must be a WPU email address (@wpu.edu.ph)</div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="password" class="form-label">Password *</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="password" name="password" required minlength="8">
                                                <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn" title="Generate secure password">
                                                    <i class="fas fa-key me-1"></i>Generate
                                                </button>
                                            </div>
                                            <div class="form-text">
                                                <i class="fas fa-info-circle me-1"></i>Minimum 8 characters. Click "Generate" for auto-generated secure password.
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="first_name" class="form-label">First Name *</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="last_name" class="form-label">Last Name *</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="user_type" class="form-label">Role *</label>
                                            <select class="form-select" id="user_type" name="user_type" required>
                                                <option value="admin">Admin</option>
                                                <option value="super_admin">Super Admin</option>
                                            </select>
                                            <div class="form-text">Only super admins can set admin or super-admin roles.</div>
                                        </div>
                                        
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-user-plus me-2"></i>Create Admin User
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <hr>
                                <?php endif; ?>
                                
                                <!-- Existing Admin Users -->
                                <h6 class="text-primary mb-2 mt-3"><i class="fas fa-users me-1"></i>Existing Admin Users</h6>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm settings-section">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <?php if (isSuperAdmin()): ?><th>Role</th><?php endif; ?>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th>Last Login</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($adminUsers as $admin): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></strong>
                                                    </td>
                                                    <td><span class="text-break"><?php echo htmlspecialchars($admin['email']); ?></span></td>
                                                    <?php if (isSuperAdmin()): ?>
                                                    <td>
                                                        <?php if ($admin['id'] != $_SESSION['user_id']): ?>
                                                            <form method="POST" action="user_actions.php" class="d-inline change-role-form">
                                                                <input type="hidden" name="action" value="change_admin_role">
                                                                <input type="hidden" name="user_id" value="<?php echo (int)$admin['id']; ?>">
                                                                <?php addFormToken(); ?>
                                                                <select name="user_type" class="form-select form-select-sm" style="width: auto; min-width: 120px;" onchange="this.form.submit()">
                                                                    <option value="admin" <?php echo ($admin['user_type'] ?? 'admin') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                                    <option value="super_admin" <?php echo ($admin['user_type'] ?? 'admin') === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                                                </select>
                                                            </form>
                                                        <?php else: ?>
                                                            <?php if (($admin['user_type'] ?? 'admin') === 'super_admin'): ?>
                                                                <span class="badge bg-dark">Super Admin</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Admin</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php endif; ?>
                                                    <td>
                                                        <span class="d-inline-flex flex-wrap gap-1">
                                                        <?php if ($admin['is_active']): ?>
                                                            <span class="badge badge-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger">Inactive</span>
                                                        <?php endif; ?>
                                                        <?php if ($admin['is_verified']): ?>
                                                            <span class="badge badge-primary">Verified</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning">Unverified</span>
                                                        <?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatDate($admin['created_at'], 'M j, Y'); ?></td>
                                                    <td>
                                                        <?php
                                                        // Get last login from system logs
                                                        $stmt = $db->prepare("SELECT created_at FROM system_logs WHERE user_id = ? AND action = 'LOGIN' ORDER BY created_at DESC LIMIT 1");
                                                        $stmt->execute([$admin['id']]);
                                                        $lastLogin = $stmt->fetch();
                                                        echo $lastLogin ? formatDate($lastLogin['created_at'], 'M j, Y g:i A') : 'Never';
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($admin['id'] != $_SESSION['user_id'] && isSuperAdmin()): ?>
                                                            <div class="btn-group btn-group-sm" role="group" aria-label="Admin user actions">
                                                                <button type="button" class="btn btn-<?php echo $admin['is_active'] ? 'warning' : 'success'; ?> btn-sm" 
                                                                        onclick="toggleUserStatus(<?php echo $admin['id']; ?>, <?php echo $admin['is_active'] ? 'false' : 'true'; ?>)" title="<?php echo $admin['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                                    <i class="fas fa-<?php echo $admin['is_active'] ? 'ban' : 'check'; ?> me-1"></i>
                                                                    <?php echo $admin['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                                </button>
                                                                <button type="button" class="btn btn-danger btn-sm" 
                                                                        onclick="deleteUser(<?php echo $admin['id']; ?>)" title="Delete user">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </div>
                                                        <?php elseif ($admin['id'] == $_SESSION['user_id']): ?>
                                                            <span class="text-muted">Current User</span>
                                                        <?php else: ?>
                                                            <span class="text-muted small">?</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (!empty($pUsers) && $pUsers['totalPages'] > 1): ?>
                                <div class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <small class="text-muted">Showing <?php echo count($adminUsers); ?> of <?php echo $pUsers['total']; ?> admin users</small>
                                    <?php echo renderPagination($pUsers['page'], $pUsers['totalPages'], '#section-admin-users'); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- System Logs -->
                <div class="row">
                    <div class="col-12 settings-section" id="section-activity">
                        <div class="card">
                            <div class="card-header">
                                <span class="section-icon"><i class="fas fa-history"></i></span>
                                <h5 class="mb-0">Recent System Activity</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $stmt = $db->prepare("SELECT sl.*, u.first_name, u.last_name FROM system_logs sl LEFT JOIN users u ON sl.user_id = u.id ORDER BY sl.created_at DESC LIMIT 10");
                                $stmt->execute();
                                $recentLogs = $stmt->fetchAll();
                                ?>
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Time</th>
                                                <th>User</th>
                                                <th>Action</th>
                                                <th>Description</th>
                                                <th>IP Address</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentLogs as $log): ?>
                                                <tr>
                                                    <td><small><?php echo formatDate($log['created_at'], 'M j, g:i A'); ?></small></td>
                                                    <td><?php echo htmlspecialchars($log['first_name'] ? $log['first_name'] . ' ' . $log['last_name'] : 'System'); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php 
                                                            echo in_array($log['action'], ['LOGIN', 'EMAIL_VERIFIED']) ? 'success' : 
                                                                (in_array($log['action'], ['LOGOUT']) ? 'info' : 'primary'); 
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $log['action'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><small><?php echo htmlspecialchars($log['description']); ?></small></td>
                                                    <td><small class="text-muted"><?php echo htmlspecialchars($log['ip_address']); ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="system_logs.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-list me-1"></i>View All Logs</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <style>
    /* Settings page: section nav and layout */
    .settings-quick-nav { background: #fff; border: 1px solid var(--border-light, #e2e8f0); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
    .settings-quick-nav .nav-scroll { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .settings-quick-nav .nav-scroll a { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border-radius: 0.375rem; color: #475569; text-decoration: none; font-size: 0.9rem; transition: background 0.15s, color 0.15s; }
    .settings-quick-nav .nav-scroll a:hover { background: rgba(0, 51, 102, 0.08); color: #003366; }
    .settings-quick-nav .nav-scroll a i { font-size: 0.85rem; opacity: 0.9; }
    .settings-section { scroll-margin-top: 5rem; }
    .settings-section .card { border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid var(--border-light, #e2e8f0); }
    .settings-section .card-header { font-size: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-light, #e2e8f0); display: flex; align-items: center; gap: 0.5rem; }
    .settings-section .card-header .section-icon { width: 2.25rem; height: 2.25rem; border-radius: 0.5rem; background: rgba(0, 51, 102, 0.1); color: #003366; display: inline-flex; align-items: center; justify-content: center; }
    .settings-section .card-body { padding: 1.25rem; }
    .master-list-preview .list-block { background: #f8fafc; border-radius: 0.5rem; padding: 1rem; border: 1px solid #e2e8f0; }
    .master-list-preview .list-block h6 { font-size: 0.875rem; font-weight: 600; color: #003366; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem; }
    .master-list-preview .list-block .badge { font-weight: 500; }
    .settings-section .table th { font-weight: 600; color: #334155; font-size: 0.875rem; }
    .settings-section .table td { vertical-align: middle; }
    .settings-section .form-text { font-size: 0.8rem; }
    .nav-tabs-master .nav-link { display: inline-flex; align-items: center; }
    .settings-section .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    #section-pardon-openers .card-body { padding: 0.75rem 1rem; }
    #section-pardon-openers .card-header { padding: 0.5rem 1rem; }
    #section-pardon-openers .card-header .section-icon { width: 1.75rem; height: 1.75rem; font-size: 0.85rem; }
    #section-pardon-openers .card-header h5 { font-size: 1rem; }
    .po-scope-combobox:focus-within { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
    .po-scope-tag { font-size: 0.75rem; padding: 0.15rem 0.4rem; }
    .po-scope-badge .po-scope-remove button:hover { opacity: 1 !important; }
    html { scroll-behavior: smooth; }
    </style>
    <?php admin_page_scripts(); ?>
        <script>
        // Pardon opener: Person field - typeable with label->user_id mapping
        (function() {
            var personMap = <?php
                $map = [];
                foreach ($pardonOpenerUsers as $u) {
                    $label = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    if (trim($u['department'] ?? '') !== '') $label .= ', ' . trim($u['department']);
                    if (trim($u['designation'] ?? '') !== '') $label .= ' (' . trim($u['designation']) . ')';
                    $label .= ' [' . ($u['user_type'] ?? '') . ']';
                    $map[$label] = (int)$u['id'];
                }
                echo json_encode($map);
            ?>;
            var searchInput = document.getElementById('po_user_search');
            var hiddenInput = document.getElementById('po_user_id');
            if (searchInput && hiddenInput) {
                function syncUserId() {
                    var val = (searchInput.value || '').trim();
                    hiddenInput.value = personMap[val] || '';
                }
                searchInput.addEventListener('input', syncUserId);
                searchInput.addEventListener('change', syncUserId);
            }
        })();
        // Pardon opener: scope combobox - typeable input with datalist, multiple selection via tags
        (function() {
            var depts = <?php echo json_encode(array_column($allDepartments, 'name')); ?>;
            var desigs = <?php echo json_encode(array_column($allDesignations, 'name')); ?>;
            var typeSel = document.getElementById('po_scope_type');
            var inputEl = document.getElementById('po_scope_input');
            var datalistEl = document.getElementById('po_scope_datalist');
            var tagsContainer = document.getElementById('po_scope_tags');
            var combobox = inputEl && inputEl.closest('.po-scope-combobox');
            if (!typeSel || !inputEl || !datalistEl || !tagsContainer) return;
            var selected = [];
            function escapeHtml(s) {
                var d = document.createElement('div');
                d.textContent = s || '';
                return d.innerHTML;
            }
            function getOptions() { return typeSel.value === 'department' ? depts : desigs; }
            function populateDatalist() {
                var arr = getOptions();
                datalistEl.innerHTML = '';
                for (var i = 0; i < arr.length; i++) {
                    var val = arr[i] || '';
                    var opt = document.createElement('option');
                    opt.value = val;
                    datalistEl.appendChild(opt);
                }
            }
            function addScope(val) {
                val = (val || '').trim();
                if (!val || selected.indexOf(val) >= 0) return;
                if (getOptions().indexOf(val) < 0) return; // must be from list
                selected.push(val);
                var tag = document.createElement('span');
                tag.className = 'badge bg-primary po-scope-tag d-inline-flex align-items-center gap-1';
                tag.innerHTML = escapeHtml(val) + ' <button type="button" class="btn btn-link p-0 border-0 text-white" style="font-size: 0.7rem; line-height: 1;" title="Remove" data-val="' + escapeHtml(val).replace(/"/g, '&quot;') + '">&times;</button>';
                tagsContainer.appendChild(tag);
                var hid = document.createElement('input');
                hid.type = 'hidden';
                hid.name = 'scope_value[]';
                hid.value = val;
                combobox.appendChild(hid);
                tag.querySelector('button').addEventListener('click', function() {
                    var v = this.getAttribute('data-val');
                    selected = selected.filter(function(x) { return x !== v; });
                    tag.remove();
                    combobox.querySelectorAll('input[name="scope_value[]"]').forEach(function(h) {
                        if (h.value === v) h.remove();
                    });
                });
            }
            function syncFromInput() {
                var val = (inputEl.value || '').trim();
                if (val && getOptions().indexOf(val) >= 0) {
                    addScope(val);
                    inputEl.value = '';
                }
            }
            typeSel.addEventListener('change', function() {
                selected = [];
                tagsContainer.innerHTML = '';
                combobox.querySelectorAll('input[name="scope_value[]"]').forEach(function(h) { h.remove(); });
                populateDatalist();
            });
            inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); syncFromInput(); }
            });
            inputEl.addEventListener('change', syncFromInput);
            inputEl.addEventListener('blur', function() { setTimeout(syncFromInput, 150); });
            populateDatalist();
            // Form validation
            var form = document.getElementById('pardonOpenerForm');
            if (form) form.addEventListener('submit', function(e) {
                var userIdInput = document.getElementById('po_user_id');
                if (userIdInput && !userIdInput.value) {
                    e.preventDefault();
                    alert('Please select a person from the list. Type to search and pick from the suggestions.');
                    document.getElementById('po_user_search').focus();
                    return false;
                }
                syncFromInput();
                var count = combobox.querySelectorAll('input[name="scope_value[]"]').length;
                if (count === 0) {
                    e.preventDefault();
                    alert('Please add at least one scope value. Type and pick from the list.');
                    inputEl.focus();
                    return false;
                }
            });
        })();
        </script>
        <!-- Master Lists Modal -->
        <div class="modal fade" id="masterListsModal" tabindex="-1" aria-labelledby="masterListsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="masterListsModalLabel"><i class="fas fa-list me-2"></i> Manage Master Lists</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs nav-tabs-master" id="masterTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="dept-tab" data-bs-toggle="tab" data-bs-target="#tab-departments" type="button" role="tab"><i class="fas fa-building me-1"></i>Departments</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="emp-tab" data-bs-toggle="tab" data-bs-target="#tab-employment" type="button" role="tab"><i class="fas fa-briefcase me-1"></i>Employment Status</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="campus-tab" data-bs-toggle="tab" data-bs-target="#tab-campuses" type="button" role="tab"><i class="fas fa-university me-1"></i>Campuses</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="designation-tab" data-bs-toggle="tab" data-bs-target="#tab-designations" type="button" role="tab"><i class="fas fa-id-badge me-1"></i>Designations</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="key-officials-tab" data-bs-toggle="tab" data-bs-target="#tab-key-officials" type="button" role="tab"><i class="fas fa-user-tie me-1"></i>Key Officials</button>
                            </li>
                        </ul>
                        <div class="tab-content mt-3">
                            <!-- Departments Tab -->
                            <div class="tab-pane fade show active" id="tab-departments" role="tabpanel" aria-labelledby="dept-tab">
                                <form method="POST" action="user_actions.php" class="mb-3 d-flex add-item-form" data-tab="dept-tab" data-list-type="department">
                                    <input type="hidden" name="action" value="add_department">
                                    <input type="text" name="name" class="form-control me-2" placeholder="New department name" required>
                                    <button class="btn btn-primary add-item-btn" type="button"><i class="fas fa-plus me-1"></i>Add</button>
                                </form>
                                <div class="mb-2">
                                    <input type="text" class="form-control form-control-sm master-list-search" placeholder="Search departments..." data-list-type="department" autocomplete="off">
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                        </thead>
                                        <tbody id="master-tbody-department">
                                            <?php foreach ($departments as $d): ?>
                                                <tr data-id="<?php echo (int)$d['id']; ?>" data-list-type="department">
                                                    <td><?php echo htmlspecialchars($d['name']); ?></td>
                                                    <td class="text-end">
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="openEditItemModal('department', <?php echo $d['id']; ?>, '<?php echo htmlspecialchars(addslashes($d['name'])); ?>')">Edit</button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem('department', <?php echo $d['id']; ?>)">Delete</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                                            </div>
                                                            <div class="d-flex justify-content-center master-list-pagination" data-list-type="department" data-current-page="<?php echo $deptPage; ?>" data-total-pages="<?php echo $deptTotalPages; ?>">
                                                                <nav aria-label="Departments pagination">
                                                                    <ul class="pagination pagination-sm mb-0">
                                                                        <?php $prev = max(1, $deptPage - 1); ?>
                                                                        <li class="page-item <?php echo $deptPage <= 1 ? 'disabled' : ''; ?>">
                                                                            <a class="page-link master-list-page-link" href="#" data-list-type="department" data-page="<?php echo $prev; ?>" aria-label="Previous">&laquo;</a>
                                                                        </li>
                                                                        <?php for ($i = 1; $i <= $deptTotalPages; $i++): ?>
                                                                            <li class="page-item <?php echo $i == $deptPage ? 'active' : ''; ?>">
                                                                                <a class="page-link master-list-page-link" href="#" data-list-type="department" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                                                            </li>
                                                                        <?php endfor; ?>
                                                                        <?php $next = min($deptTotalPages, $deptPage + 1); ?>
                                                                        <li class="page-item <?php echo $deptPage >= $deptTotalPages ? 'disabled' : ''; ?>">
                                                                            <a class="page-link master-list-page-link" href="#" data-list-type="department" data-page="<?php echo $next; ?>" aria-label="Next">&raquo;</a>
                                                                        </li>
                                                                    </ul>
                                                                </nav>
                                                            </div>
                            </div>

                            <!-- Employment Status Tab -->
                            <div class="tab-pane fade" id="tab-employment" role="tabpanel" aria-labelledby="emp-tab">
                                <form method="POST" action="user_actions.php" class="mb-3 d-flex add-item-form" data-tab="emp-tab" data-list-type="employment_status">
                                    <input type="hidden" name="action" value="add_employment_status">
                                    <input type="text" name="name" class="form-control me-2" placeholder="New status (e.g. Full-time)" required>
                                    <button class="btn btn-primary add-item-btn" type="button"><i class="fas fa-plus me-1"></i>Add</button>
                                </form>
                                <div class="mb-2">
                                    <input type="text" class="form-control form-control-sm master-list-search" placeholder="Search statuses..." data-list-type="employment_status" autocomplete="off">
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                        </thead>
                                        <tbody id="master-tbody-employment_status">
                                            <?php foreach ($employmentStatuses as $e): ?>
                                                <tr data-id="<?php echo (int)$e['id']; ?>" data-list-type="employment_status">
                                                    <td><?php echo htmlspecialchars($e['name']); ?></td>
                                                    <td class="text-end">
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="openEditItemModal('employment_status', <?php echo $e['id']; ?>, '<?php echo htmlspecialchars(addslashes($e['name'])); ?>')">Edit</button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem('employment_status', <?php echo $e['id']; ?>)">Delete</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-center master-list-pagination" data-list-type="employment_status" data-current-page="<?php echo $empPage; ?>" data-total-pages="<?php echo $empTotalPages; ?>">
                                    <nav aria-label="Employment pagination">
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php $prevE = max(1, $empPage - 1); ?>
                                            <li class="page-item <?php echo $empPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="employment_status" data-page="<?php echo $prevE; ?>" aria-label="Previous">&laquo;</a>
                                            </li>
                                            <?php for ($i = 1; $i <= $empTotalPages; $i++): ?>
                                                <li class="page-item <?php echo $i == $empPage ? 'active' : ''; ?>">
                                                    <a class="page-link master-list-page-link" href="#" data-list-type="employment_status" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <?php $nextE = min($empTotalPages, $empPage + 1); ?>
                                            <li class="page-item <?php echo $empPage >= $empTotalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="employment_status" data-page="<?php echo $nextE; ?>" aria-label="Next">&raquo;</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>

                            <!-- Campuses Tab -->
                            <div class="tab-pane fade" id="tab-campuses" role="tabpanel" aria-labelledby="campus-tab">
                                <form method="POST" action="user_actions.php" class="mb-3 d-flex add-item-form" data-tab="campus-tab" data-list-type="campus">
                                    <input type="hidden" name="action" value="add_campus">
                                    <input type="text" name="name" class="form-control me-2" placeholder="New campus name" required>
                                    <button class="btn btn-primary add-item-btn" type="button"><i class="fas fa-plus me-1"></i>Add</button>
                                </form>
                                <div class="mb-2">
                                    <input type="text" class="form-control form-control-sm master-list-search" placeholder="Search campuses..." data-list-type="campus" autocomplete="off">
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                        </thead>
                                        <tbody id="master-tbody-campus">
                                            <?php foreach ($campuses as $c): ?>
                                                <tr data-id="<?php echo (int)$c['id']; ?>" data-list-type="campus">
                                                    <td><?php echo htmlspecialchars($c['name']); ?></td>
                                                    <td class="text-end">
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="openEditItemModal('campus', <?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">Edit</button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem('campus', <?php echo $c['id']; ?>)">Delete</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-center master-list-pagination" data-list-type="campus" data-current-page="<?php echo $campusPage; ?>" data-total-pages="<?php echo $campusTotalPages; ?>">
                                    <nav aria-label="Campus pagination">
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php $prevC = max(1, $campusPage - 1); ?>
                                            <li class="page-item <?php echo $campusPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="campus" data-page="<?php echo $prevC; ?>" aria-label="Previous">&laquo;</a>
                                            </li>
                                            <?php for ($i = 1; $i <= $campusTotalPages; $i++): ?>
                                                <li class="page-item <?php echo $i == $campusPage ? 'active' : ''; ?>">
                                                    <a class="page-link master-list-page-link" href="#" data-list-type="campus" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <?php $nextC = min($campusTotalPages, $campusPage + 1); ?>
                                            <li class="page-item <?php echo $campusPage >= $campusTotalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="campus" data-page="<?php echo $nextC; ?>" aria-label="Next">&raquo;</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>

                            <!-- Designations Tab -->
                            <div class="tab-pane fade" id="tab-designations" role="tabpanel" aria-labelledby="designation-tab">
                                <form method="POST" action="user_actions.php" class="mb-3 d-flex add-item-form" data-tab="designation-tab" data-list-type="designation">
                                    <input type="hidden" name="action" value="add_designation">
                                    <input type="text" name="name" class="form-control me-2" placeholder="New designation (e.g. Dean, Program Chair)" required>
                                    <button class="btn btn-primary add-item-btn" type="button"><i class="fas fa-plus me-1"></i>Add</button>
                                </form>
                                <div class="mb-2">
                                    <input type="text" class="form-control form-control-sm master-list-search" placeholder="Search designations..." data-list-type="designation" autocomplete="off">
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                        </thead>
                                        <tbody id="master-tbody-designation">
                                            <?php foreach ($designations as $d): ?>
                                                <tr data-id="<?php echo (int)$d['id']; ?>" data-list-type="designation">
                                                    <td><?php echo htmlspecialchars($d['name']); ?></td>
                                                    <td class="text-end">
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="openEditItemModal('designation', <?php echo $d['id']; ?>, '<?php echo htmlspecialchars(addslashes($d['name'])); ?>')">Edit</button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem('designation', <?php echo $d['id']; ?>)">Delete</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-center master-list-pagination" data-list-type="designation" data-current-page="<?php echo $designationPage; ?>" data-total-pages="<?php echo $designationTotalPages; ?>">
                                    <nav aria-label="Designation pagination">
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php $prevD = max(1, $designationPage - 1); ?>
                                            <li class="page-item <?php echo $designationPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="designation" data-page="<?php echo $prevD; ?>" aria-label="Previous">&laquo;</a>
                                            </li>
                                            <?php for ($i = 1; $i <= $designationTotalPages; $i++): ?>
                                                <li class="page-item <?php echo $i == $designationPage ? 'active' : ''; ?>">
                                                    <a class="page-link master-list-page-link" href="#" data-list-type="designation" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <?php $nextD = min($designationTotalPages, $designationPage + 1); ?>
                                            <li class="page-item <?php echo $designationPage >= $designationTotalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="designation" data-page="<?php echo $nextD; ?>" aria-label="Next">&raquo;</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>

                            <!-- Key Officials Tab -->
                            <div class="tab-pane fade" id="tab-key-officials" role="tabpanel" aria-labelledby="key-officials-tab">
                                <form method="POST" action="user_actions.php" class="mb-3 d-flex add-item-form" data-tab="key-officials-tab" data-list-type="key_official">
                                    <input type="hidden" name="action" value="add_key_official">
                                    <input type="text" name="name" class="form-control me-2" placeholder="New key official (e.g. President, VP Academic)" required>
                                    <button class="btn btn-primary add-item-btn" type="button"><i class="fas fa-plus me-1"></i>Add</button>
                                </form>
                                <div class="mb-2">
                                    <input type="text" class="form-control form-control-sm master-list-search" placeholder="Search key officials..." data-list-type="key_official" autocomplete="off">
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                        </thead>
                                        <tbody id="master-tbody-key_official">
                                            <?php foreach ($keyOfficials as $k): ?>
                                                <tr data-id="<?php echo (int)$k['id']; ?>" data-list-type="key_official">
                                                    <td><?php echo htmlspecialchars($k['name']); ?></td>
                                                    <td class="text-end">
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-outline-secondary" onclick="openEditItemModal('key_official', <?php echo $k['id']; ?>, '<?php echo htmlspecialchars(addslashes($k['name'])); ?>')">Edit</button>
                                                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem('key_official', <?php echo $k['id']; ?>)">Delete</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-center master-list-pagination" data-list-type="key_official" data-current-page="<?php echo $keyOfficialsPage; ?>" data-total-pages="<?php echo $keyOfficialsTotalPages; ?>">
                                    <nav aria-label="Key Officials pagination">
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php $prevK = max(1, $keyOfficialsPage - 1); ?>
                                            <li class="page-item <?php echo $keyOfficialsPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="key_official" data-page="<?php echo $prevK; ?>" aria-label="Previous">&laquo;</a>
                                            </li>
                                            <?php for ($i = 1; $i <= $keyOfficialsTotalPages; $i++): ?>
                                                <li class="page-item <?php echo $i == $keyOfficialsPage ? 'active' : ''; ?>">
                                                    <a class="page-link master-list-page-link" href="#" data-list-type="key_official" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <?php $nextK = min($keyOfficialsTotalPages, $keyOfficialsPage + 1); ?>
                                            <li class="page-item <?php echo $keyOfficialsPage >= $keyOfficialsTotalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link master-list-page-link" href="#" data-list-type="key_official" data-page="<?php echo $nextK; ?>" aria-label="Next">&raquo;</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Item Modal (reused for all master lists) -->
        <div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editItemModalLabel">Edit Item</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="user_actions.php" id="editItemForm">
                        <div class="modal-body">
                            <input type="hidden" name="action" id="edit_action" value="">
                            <input type="hidden" name="id" id="edit_id" value="">
                            <div class="mb-3">
                                <label for="edit_name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="edit_name" name="name" required>
                            </div>
                            <div id="editItemMessage" class="alert" style="display: none;"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="editItemSubmitBtn">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        // Password visibility toggles for change password form
        function setupPasswordToggle(buttonId, inputId) {
            const toggleBtn = document.getElementById(buttonId);
            const passwordInput = document.getElementById(inputId);
            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    const icon = toggleBtn.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-eye');
                        icon.classList.toggle('fa-eye-slash');
                    }
                });
            }
        }
        
        // Setup password toggles
        setupPasswordToggle('toggleCurrentPassword', 'current_password');
        setupPasswordToggle('toggleNewPassword', 'new_password');
        setupPasswordToggle('toggleConfirmPassword', 'confirm_password');
        
        // Auto-generate password (for create admin form)
        const generatePasswordBtn = document.getElementById('generatePasswordBtn');
        if (generatePasswordBtn) {
            generatePasswordBtn.addEventListener('click', function() {
                // Generate a random 12-character password
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                let password = '';
                for (let i = 0; i < 12; i++) {
                    password += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                const passwordInput = document.getElementById('password');
                if (passwordInput) {
                    passwordInput.value = password;
                    passwordInput.type = 'text'; // Show password temporarily
                    passwordInput.focus();
                    passwordInput.select();
                    
                    // Hide password after 5 seconds
                    setTimeout(function() {
                        passwordInput.type = 'password';
                    }, 5000);
                }
            });
        }
        
        function toggleUserStatus(userId, newStatus) {
            const action = newStatus ? 'activate' : 'deactivate';
            if (confirm(`Are you sure you want to ${action} this user?`)) {
                // Create a form to submit the action
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'user_actions.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'toggle_status';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'is_active';
                statusInput.value = newStatus;
                
                form.appendChild(actionInput);
                form.appendChild(userIdInput);
                form.appendChild(statusInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteUser(userId) {
            if (confirm('Are you sure you want to delete this admin user? This action cannot be undone.')) {
                // Create a form to submit the action
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'user_actions.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_user';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                
                form.appendChild(actionInput);
                form.appendChild(userIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Open the edit modal and populate fields
        function openEditItemModal(type, id, currentName) {
            const modal = new bootstrap.Modal(document.getElementById('editItemModal'));
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = currentName;
            document.getElementById('edit_action').value = 'update_' + type;
            document.getElementById('editItemModalLabel').textContent = 'Edit ' + (type.replace(/_/g, ' ')).replace(/\b\w/g, c => c.toUpperCase());
            modal.show();
        }

        // Delete an item from a master list (AJAX, no reload)
        function deleteItem(type, id) {
            if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) return;
            const formData = new FormData();
            formData.set('action', 'delete_' + type);
            formData.set('id', id);

            fetch('user_actions.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    const row = document.querySelector('tr[data-list-type="' + type + '"][data-id="' + id + '"]');
                    if (row) row.remove();
                    showMasterListToast(data.message, 'success');
                } else {
                    showMasterListToast(data.error || 'Delete failed.', 'danger');
                }
            })
            .catch(function() {
                showMasterListToast('Request failed. Please try again.', 'danger');
            });
        }

        function showMasterListToast(message, type) {
            type = type || 'success';
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show position-fixed';
            alertDiv.style.cssText = 'top: 1rem; right: 1rem; z-index: 9999; min-width: 280px;';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            document.body.appendChild(alertDiv);
            setTimeout(function() {
                if (alertDiv.parentNode) alertDiv.remove();
            }, 4000);
        }

        function renderMasterListRow(type, id, name) {
            const escaped = ('' + name).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
            return '<tr data-id="' + id + '" data-list-type="' + type + '">' +
                '<td>' + escapeHtml(name) + '</td>' +
                '<td class="text-end"><div class="btn-group btn-group-sm" role="group">' +
                '<button type="button" class="btn btn-outline-secondary" onclick="openEditItemModal(\'' + type + '\', ' + id + ', \'' + escaped + '\')">Edit</button>' +
                '<button type="button" class="btn btn-outline-danger" onclick="deleteItem(\'' + type + '\', ' + id + ')">Delete</button>' +
                '</div></td></tr>';
        }
        function escapeHtml(s) {
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        // Handle edit form submission via AJAX to keep modal open
        document.addEventListener('DOMContentLoaded', function() {
            const editItemForm = document.getElementById('editItemForm');
            if (editItemForm) {
                editItemForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(editItemForm);
                    const submitBtn = document.getElementById('editItemSubmitBtn');
                    const messageDiv = document.getElementById('editItemMessage');
                    
                    if (!submitBtn) {
                        console.error('Submit button not found');
                        return;
                    }
                    
                    const originalBtnText = submitBtn.innerHTML;
                    
                    // Disable submit button and show loading state
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
                    if (messageDiv) {
                        messageDiv.style.display = 'none';
                    }
                    
                    // Add AJAX header
                    fetch('user_actions.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const action = formData.get('action');
                            const itemType = action.replace('update_', '');
                            const id = formData.get('id');
                            const newName = document.getElementById('edit_name').value;
                            const row = document.querySelector('tr[data-list-type="' + itemType + '"][data-id="' + id + '"]');
                            if (row) {
                                const nameCell = row.querySelector('td:first-child');
                                if (nameCell) nameCell.textContent = newName;
                                const editBtn = row.querySelector('button.btn-outline-secondary');
                                if (editBtn) editBtn.setAttribute('onclick', "openEditItemModal('" + itemType + "', " + id + ", '" + ('' + newName).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "')");
                            }
                            if (messageDiv) {
                                messageDiv.className = 'alert alert-success';
                                messageDiv.textContent = data.message;
                                messageDiv.style.display = 'block';
                            }
                            const editModal = bootstrap.Modal.getInstance(document.getElementById('editItemModal'));
                            if (editModal) editModal.hide();
                            showMasterListToast(data.message, 'success');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                        } else {
                            // Show error message
                            if (messageDiv) {
                                messageDiv.className = 'alert alert-danger';
                                messageDiv.textContent = data.error || 'An error occurred while saving.';
                                messageDiv.style.display = 'block';
                            }
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        if (messageDiv) {
                            messageDiv.className = 'alert alert-danger';
                            messageDiv.textContent = 'An error occurred while saving. Please try again.';
                            messageDiv.style.display = 'block';
                        }
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                    });
                });
            }
        });

        // Helper function to get active tab from item type
        function getActiveTabFromType(type) {
            const tabMap = {
                'department': 'dept-tab',
                'employment_status': 'emp-tab',
                'campus': 'campus-tab',
                'designation': 'designation-tab',
                'key_official': 'key-officials-tab'
            };
            return tabMap[type] || 'dept-tab';
        }

        // Handle Add button click (no form submit avoids page load)
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.add-item-form').forEach(function(form) {
                const submitBtn = form.querySelector('.add-item-btn');
                if (!submitBtn) return;
                // Prevent Enter from submitting form (page reload); instead trigger Add button
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitBtn.click();
                });
                submitBtn.addEventListener('click', function() {
                    var nameInput = form.querySelector('input[name="name"]');
                    if (nameInput && !nameInput.value.trim()) {
                        nameInput.focus();
                        showMasterListToast('Please enter a name.', 'danger');
                        return;
                    }
                    var formData = new FormData(form);
                    var originalBtnText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Adding...';
                    fetch('user_actions.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            form.reset();
                            var listType = form.getAttribute('data-list-type');
                            if (listType) {
                                var container = document.querySelector('.master-list-pagination[data-list-type="' + listType + '"]');
                                var currentPage = container ? parseInt(container.getAttribute('data-current-page'), 10) || 1 : 1;
                                var search = getMasterListSearch(listType);
                                loadMasterListPage(listType, currentPage, search);
                            }
                            showMasterListToast(data.message, 'success');
                        } else {
                            showMasterListToast(data.error || 'An error occurred while adding the item.', 'danger');
                        }
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                    })
                    .catch(function(err) {
                        console.error('Error:', err);
                        showMasterListToast('An error occurred. Please try again.', 'danger');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                    });
                });
            });
        });

        // Master list pagination: load page via AJAX (no reload)
        function renderMasterListPagination(listType, currentPage, totalPages) {
            var prev = Math.max(1, currentPage - 1);
            var next = Math.min(totalPages, currentPage + 1);
            var html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
            html += '<li class="page-item' + (currentPage <= 1 ? ' disabled' : '') + '">';
            html += '<a class="page-link master-list-page-link" href="#" data-list-type="' + listType + '" data-page="' + prev + '" aria-label="Previous">&laquo;</a></li>';
            for (var i = 1; i <= totalPages; i++) {
                html += '<li class="page-item' + (i === currentPage ? ' active' : '') + '">';
                html += '<a class="page-link master-list-page-link" href="#" data-list-type="' + listType + '" data-page="' + i + '">' + i + '</a></li>';
            }
            html += '<li class="page-item' + (currentPage >= totalPages ? ' disabled' : '') + '">';
            html += '<a class="page-link master-list-page-link" href="#" data-list-type="' + listType + '" data-page="' + next + '" aria-label="Next">&raquo;</a></li>';
            html += '</ul></nav>';
            return html;
        }

        function loadMasterListPage(listType, page, search) {
            var tbody = document.getElementById('master-tbody-' + listType);
            var container = document.querySelector('.master-list-pagination[data-list-type="' + listType + '"]');
            if (!tbody || !container) return;
            var formData = new FormData();
            formData.set('action', 'get_master_list_page');
            formData.set('list_type', listType);
            formData.set('page', page);
            if (search && search.trim() !== '') {
                formData.set('search', search.trim());
            }
            fetch('user_actions.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    tbody.innerHTML = '';
                    if (data.items && data.items.length > 0) {
                        data.items.forEach(function(item) {
                            tbody.insertAdjacentHTML('beforeend', renderMasterListRow(listType, item.id, item.name));
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="2" class="text-muted text-center py-3">No matching items found.</td></tr>';
                    }
                    container.setAttribute('data-current-page', data.page);
                    container.setAttribute('data-total-pages', data.totalPages);
                    container.innerHTML = renderMasterListPagination(listType, data.page, data.totalPages);
                }
            })
            .catch(function() { showMasterListToast('Failed to load page.', 'danger'); });
        }

        function getMasterListSearch(listType) {
            var input = document.querySelector('.master-list-search[data-list-type="' + listType + '"]');
            return input ? input.value.trim() : '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('masterListsModal');
            if (modal) modal.addEventListener('click', function(e) {
                var link = e.target.closest('a.master-list-page-link');
                if (!link || link.getAttribute('href') !== '#') return;
                e.preventDefault();
                var li = link.closest('li');
                if (li && li.classList.contains('disabled')) return;
                var listType = link.getAttribute('data-list-type');
                var page = parseInt(link.getAttribute('data-page'), 10);
                var search = getMasterListSearch(listType);
                if (listType && page >= 1) loadMasterListPage(listType, page, search);
            });

            // Real-time search filtering (debounced)
            var searchDebounce = {};
            document.querySelectorAll('.master-list-search').forEach(function(input) {
                var listType = input.getAttribute('data-list-type');
                if (!listType) return;
                input.addEventListener('input', function() {
                    clearTimeout(searchDebounce[listType]);
                    searchDebounce[listType] = setTimeout(function() {
                        loadMasterListPage(listType, 1, input.value.trim());
                    }, 250);
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(searchDebounce[listType]);
                        loadMasterListPage(listType, 1, input.value.trim());
                    }
                });
            });
        });
    </script>
    <script>
    // Auto-open master lists modal and show the correct tab when a page param is present
    // or when we need to keep it open after editing
    document.addEventListener('DOMContentLoaded', function() {
        const params = new URLSearchParams(window.location.search);
        const modalNeeded = params.has('dept_page') || params.has('emp_page') || params.has('campus_page') || params.has('designation_page') || params.has('key_officials_page');
        const keepOpen = sessionStorage.getItem('keepMasterModalOpen') === 'true';
        const activeTab = sessionStorage.getItem('activeTab');
        
        // Clear the session storage flags
        if (keepOpen) {
            sessionStorage.removeItem('keepMasterModalOpen');
            sessionStorage.removeItem('activeTab');
        }
        
        if (modalNeeded || keepOpen) {
            // Show modal
            const masterModalEl = document.getElementById('masterListsModal');
            if (masterModalEl) {
                const modal = new bootstrap.Modal(masterModalEl);
                modal.show();

                // Determine which tab to show
                let tabEl = null;
                if (keepOpen && activeTab) {
                    // Use the stored active tab
                    tabEl = document.querySelector('#masterTabs button#' + activeTab);
                } else if (params.has('emp_page')) {
                    tabEl = document.querySelector('#masterTabs button[data-bs-target="#tab-employment"]');
                } else if (params.has('campus_page')) {
                    tabEl = document.querySelector('#masterTabs button[data-bs-target="#tab-campuses"]');
                } else if (params.has('designation_page')) {
                    tabEl = document.querySelector('#masterTabs button[data-bs-target="#tab-designations"]');
                } else if (params.has('key_officials_page')) {
                    tabEl = document.querySelector('#masterTabs button[data-bs-target="#tab-key-officials"]');
                } else {
                    tabEl = document.querySelector('#masterTabs button[data-bs-target="#tab-departments"]');
                }
                
                if (tabEl) {
                    new bootstrap.Tab(tabEl).show();
                }
            }
        }
    });
    </script>
</body>
</html>

