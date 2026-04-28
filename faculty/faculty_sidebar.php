<?php
$currentPage = basename($_SERVER['PHP_SELF']);

// CRITICAL: Get base path for absolute URLs - fixes PWA navigation issues on mobile
// This ensures links work correctly regardless of how the PWA resolves the base URL
$facultyBasePath = '';
if (function_exists('getBasePath')) {
    $facultyBasePath = getBasePath();
} else {
    // Fallback: calculate from script location
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($scriptName) {
        $scriptDir = dirname($scriptName);
        if (strpos($scriptDir, '/faculty') !== false) {
            $facultyBasePath = substr($scriptDir, 0, strpos($scriptDir, '/faculty'));
        }
    }
}
// Normalize: ensure it starts with / and doesn't end with /
if ($facultyBasePath && $facultyBasePath !== '/') {
    $facultyBasePath = '/' . trim($facultyBasePath, '/');
} elseif ($facultyBasePath === '/') {
    $facultyBasePath = '';
}
$facultyPath = $facultyBasePath . '/faculty';
$rootPath = $facultyBasePath;

// Attempt to resolve the faculty profile picture for sidebar display.
$profile_picture = '';
$user_name = $_SESSION['user_name'] ?? 'Faculty Member';
$user_email = $_SESSION['user_email'] ?? '';
$user_type = $_SESSION['user_type'] ?? 'faculty'; // Default to 'faculty' if not set
$user_designation = '';

if (isset($_SESSION['user_id'])) {
    // If the including page already fetched profile data, prefer those variables
    if (isset($profile) && !empty($profile['profile_picture'])) {
        $profile_picture = $profile['profile_picture'];
        if (!empty($profile['designation'])) {
            $user_designation = trim($profile['designation']);
        }
    } elseif (isset($facultyProfile) && !empty($facultyProfile['profile_picture'])) {
        $profile_picture = $facultyProfile['profile_picture'];
        if (!empty($facultyProfile['designation'])) {
            $user_designation = trim($facultyProfile['designation']);
        }
    } else {
        // Try a lightweight DB lookup if Database class is available (or can be included)
        $dbLoaded = class_exists('Database');
        if (!$dbLoaded && file_exists(__DIR__ . '/../includes/database.php')) {
            include_once __DIR__ . '/../includes/database.php';
            $dbLoaded = class_exists('Database');
        }

        if ($dbLoaded) {
            try {
                $database = Database::getInstance();
                $db = $database->getConnection();
                // Fetch profile_picture, user_type, and designation
                $stmt = $db->prepare("SELECT fp.profile_picture, fp.designation, u.user_type FROM faculty_profiles fp INNER JOIN users u ON fp.user_id = u.id WHERE fp.user_id = ? LIMIT 1");
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (!empty($row['profile_picture'])) {
                        $profile_picture = $row['profile_picture'];
                    }
                    if (!empty($row['user_type'])) {
                        $user_type = $row['user_type'];
                    }
                    if (!empty($row['designation'])) {
                        $user_designation = $row['designation'];
                    }
                } else {
                    // If no profile found, still try to get user_type from users table
                    $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$_SESSION['user_id']]);
                    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($userRow && !empty($userRow['user_type'])) {
                        $user_type = $userRow['user_type'];
                    }
                }
            } catch (Exception $e) {
                // ignore DB errors in sidebar - non-fatal
            }
        }
    }
}

// Check if user is a Dean
$isDean = (strtolower($user_designation) === 'dean');

// Check if user has pardon opener assignments (can see people assigned to them)
$hasPardonOpenerAssignments = false;
if (isset($_SESSION['user_id']) && function_exists('hasPardonOpenerAssignments')) {
    $hasPardonOpenerAssignments = hasPardonOpenerAssignments($_SESSION['user_id']);
}

// Check if employee is in any pardon opener's scope (can submit pardon request letters)
$isEmployeeInPardonScope = false;
if (isset($_SESSION['user_id']) && function_exists('isEmployeeInAnyPardonScope')) {
    try {
        if (!isset($db) && class_exists('Database')) {
            $database = Database::getInstance();
            $db = $database->getConnection();
        }
        if (isset($db)) {
            $empStmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
            $empStmt->execute([$_SESSION['user_id']]);
            $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
            if ($empRow && !empty($empRow['employee_id'])) {
                $isEmployeeInPardonScope = isEmployeeInAnyPardonScope($empRow['employee_id'], $db);
            }
        }
    } catch (Exception $e) { /* ignore */ }
}

$isTarfEndorserTarget = false;
$isTarfPresidentViewer = false;
$isTarfFundAvailabilityActor = false;
if (isset($_SESSION['user_id'])) {
    $tarfWf = __DIR__ . '/../includes/tarf_workflow.php';
    if (is_file($tarfWf)) {
        require_once $tarfWf;
        try {
            if (!isset($db) && class_exists('Database')) {
                $database = Database::getInstance();
                $db = $database->getConnection();
            }
            if (isset($db) && function_exists('tarf_is_endorser_target_user')) {
                $isTarfEndorserTarget = tarf_is_endorser_target_user((int) $_SESSION['user_id'], $db);
            }
            if (isset($db) && function_exists('tarf_user_holds_fund_availability_designation')) {
                $isTarfFundAvailabilityActor = tarf_user_holds_fund_availability_designation((int) $_SESSION['user_id'], $db);
            }
            if (function_exists('tarf_is_president_key_official_viewer')) {
                $isTarfPresidentViewer = tarf_is_president_key_official_viewer((int) $_SESSION['user_id']);
            }
        } catch (Exception $e) { /* ignore */ }
    }
}

// Determine the role display text: show designation when available, otherwise Faculty/Staff Member
$role_display = !empty($user_designation)
    ? trim($user_designation)
    : (($user_type === 'staff') ? 'Staff Member' : 'Faculty Member');
?>

<nav id="sidebar" class="sidebar faculty-sidebar" role="navigation" aria-label="Faculty navigation">
    <!-- Mobile: close button (visible only on small screens) -->
    <button type="button" id="closeSidebar" class="sidebar-close-btn d-lg-none" aria-label="Close menu">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
    <!-- User Profile Card -->
    <div class="sidebar-user-card">
        <div class="user-avatar-wrapper">
            <?php if (!empty($profile_picture)): ?>
                <img src="<?php echo htmlspecialchars($rootPath); ?>/uploads/profiles/<?php echo htmlspecialchars($profile_picture); ?>?t=<?php echo time(); ?>" alt="Profile Picture" class="user-avatar-img">
            <?php else: ?>
                <div class="user-avatar-circle">
                    <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="user-details">
            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="user-role"><?php echo htmlspecialchars($role_display); ?></div>
        </div>
    </div>
    
    <div class="sidebar-scroll">
        <nav class="sidebar-navigation">
            <!-- Main Section -->
            <div class="nav-section" role="group" aria-label="Home">
                <div class="nav-section-label">Home</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/dashboard.php', $facultyBasePath)); ?>"<?php echo $currentPage === 'dashboard.php' ? ' aria-current="page"' : ''; ?> title="Dashboard overview">
                            <span class="nav-icon" aria-hidden="true"><i class="fas fa-th-large"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'announcements.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/announcements.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-bullhorn"></i></span>
                            <span class="nav-text">Announcements</span>
                        </a>
                    </li>
                    <?php if ($isEmployeeInPardonScope): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'supervisor_scope_announcements.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/supervisor_scope_announcements.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
                            <span class="nav-text">From My Supervisor</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'calendar.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/calendar.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                            <span class="nav-text">Calendar</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'feedback.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/feedback.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-comment-dots"></i></span>
                            <span class="nav-text">Employee feedback</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Profile Section -->
            <div class="nav-section" role="group" aria-label="My Information">
                <div class="nav-section-label">My Information</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/profile.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-user"></i></span>
                            <span class="nav-text">Profile</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'pds.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/pds.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span class="nav-text">Personal Data Sheet</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'view_logs.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/view_logs.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Attendance Logs</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'declare_official_time.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/declare_official_time.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clock"></i></span>
                            <span class="nav-text">Declare Official Time</span>
                        </a>
                    </li>
                    <?php if ($isEmployeeInPardonScope): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'request_pardon.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/request_pardon.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-file-signature"></i></span>
                            <span class="nav-text">Request Pardon</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Documents Section -->
            <div class="nav-section" role="group" aria-label="Documents">
                <div class="nav-section-label">Documents</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'requirements.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/requirements.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-tasks"></i></span>
                            <span class="nav-text">Requirements</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'submissions.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/submissions.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                            <span class="nav-text">My Submissions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'tarf_request.php' || $currentPage === 'tarf_request_view.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/tarf_request.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-plane-departure"></i></span>
                            <span class="nav-text">TARF Request</span>
                        </a>
                    </li>
                    <?php if ($isTarfEndorserTarget): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'tarf_endorser_queue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/tarf_endorser_queue.php', $facultyBasePath)); ?>" data-count-key="tarf_endorser">
                            <span class="nav-icon"><i class="fas fa-stamp"></i></span>
                            <span class="nav-text">TARF / NTARF — Endorser inbox</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="tarf_endorser" style="display: none;">0</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($isTarfFundAvailabilityActor): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'tarf_fund_availability_queue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/tarf_fund_availability_queue.php', $facultyBasePath)); ?>" data-count-key="tarf_fund">
                            <span class="nav-icon"><i class="fas fa-coins"></i></span>
                            <span class="nav-text">TARF / NTARF — Budget / Accounting</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="tarf_fund" style="display: none;">0</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($isTarfPresidentViewer): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'tarf_president_queue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/tarf_president_queue.php', $facultyBasePath)); ?>" data-count-key="tarf_president">
                            <span class="nav-icon"><i class="fas fa-landmark"></i></span>
                            <span class="nav-text">TARF — President (final)</span>
                            <span class="scope-activity-badge badge bg-info ms-2" data-count-key="tarf_president" style="display: none;">0</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <?php if ($isDean || $hasPardonOpenerAssignments): ?>
            <!-- Dean Management / Pardon Opener Section -->
            <div class="nav-section" role="group" aria-label="<?php echo $isDean ? 'Dean Management' : 'People in My Scope'; ?>">
                <div class="nav-section-label"><?php echo $isDean ? 'Dean Management' : 'People in My Scope'; ?></div>
                <ul class="nav-items">
                    <?php if ($hasPardonOpenerAssignments): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'my_assigned_employees.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/my_assigned_employees.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-user-shield"></i></span>
                            <span class="nav-text">My Assigned Employees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'supervisor_announcements.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/supervisor_announcements.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-bullhorn"></i></span>
                            <span class="nav-text">Department Announcements</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'supervisor_scope_calendar.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/supervisor_scope_calendar.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-calendar-plus"></i></span>
                            <span class="nav-text">Events & Meetings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'pardon_request_letters.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/pardon_request_letters.php', $facultyBasePath)); ?>" data-count-key="pardon_letters">
                            <span class="nav-icon"><i class="fas fa-envelope-open-text"></i></span>
                            <span class="nav-text">Requesting Open Pardon</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="pardon_letters" style="display: none;">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'tarf_supervisor_queue.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/tarf_supervisor_queue.php', $facultyBasePath)); ?>" data-count-key="tarf_supervisor">
                            <span class="nav-icon"><i class="fas fa-user-check"></i></span>
                            <span class="nav-text">TARF — Supervisor</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="tarf_supervisor" style="display: none;">0</span>
                        </a>
                    </li>
                    <?php if (!$isDean): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'dtr_submissions.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/dtr_submissions.php', $facultyBasePath)); ?>" data-count-key="dtr_submissions">
                            <span class="nav-icon"><i class="fas fa-tasks"></i></span>
                            <span class="nav-text">DTR Submissions</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="dtr_submissions" style="display: none;">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'official_time_requests_dean.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/official_time_requests_dean.php', $facultyBasePath)); ?>" data-count-key="official_time">
                            <span class="nav-icon"><i class="fas fa-clock"></i></span>
                            <span class="nav-text">Official Time Requests</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="official_time" style="display: none;">0</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($isDean): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'dtr_submissions.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/dtr_submissions.php', $facultyBasePath)); ?>" data-count-key="dtr_submissions">
                            <span class="nav-icon"><i class="fas fa-tasks"></i></span>
                            <span class="nav-text">DTR Submissions</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="dtr_submissions" style="display: none;">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'official_time_requests_dean.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/official_time_requests_dean.php', $facultyBasePath)); ?>" data-count-key="official_time">
                            <span class="nav-icon"><i class="fas fa-clock"></i></span>
                            <span class="nav-text">Official Time Requests</span>
                            <span class="scope-activity-badge badge bg-warning ms-2" data-count-key="official_time" style="display: none;">0</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Settings Section -->
            <div class="nav-section" role="group" aria-label="Settings">
                <div class="nav-section-label">Settings</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'change_password.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($facultyPath . '/change_password.php', $facultyBasePath)); ?>">
                            <span class="nav-icon"><i class="fas fa-key"></i></span>
                            <span class="nav-text">Change Password</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
    
    <div class="sidebar-footer">
        <a class="sidebar-logout-btn" href="<?php echo htmlspecialchars(clean_url($rootPath . '/logout.php', $facultyBasePath)); ?>" data-logout-url="<?php echo htmlspecialchars(clean_url($rootPath . '/logout.php', $facultyBasePath)); ?>" onclick="event.preventDefault(); if(typeof confirmLogout === 'function') { confirmLogout(this); } else { if(confirm('Are you sure you want to logout?')) { window.location.href = this.getAttribute('data-logout-url') || '<?php echo htmlspecialchars(clean_url($rootPath . '/logout.php', $facultyBasePath)); ?>'; } } return false;">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<script>
(function() {
    var scrollEl = document.querySelector('.faculty-sidebar .sidebar-scroll');
    if (!scrollEl) return;
    function saveScroll() {
        sessionStorage.setItem('facultySidebarScroll', String(scrollEl.scrollTop));
    }
    function restoreScroll() {
        var saved = sessionStorage.getItem('facultySidebarScroll');
        if (saved !== null) {
            var pos = parseInt(saved, 10);
            if (pos > 0) {
                scrollEl.scrollTop = pos;
            }
            sessionStorage.removeItem('facultySidebarScroll');
        }
    }
    scrollEl.addEventListener('click', function(e) {
        var link = e.target.closest('a');
        if (link && link.href && link.href.indexOf('#') !== 0 && link.href.indexOf('javascript:') !== 0) {
            saveScroll();
        }
    }, true);
    window.addEventListener('pagehide', saveScroll);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            requestAnimationFrame(function() {
                requestAnimationFrame(restoreScroll);
            });
        });
    } else {
        requestAnimationFrame(function() {
            requestAnimationFrame(restoreScroll);
        });
    }
})();
</script>

<?php
// Prevent duplicate bottom navigation - only render once
if (!defined('FACULTY_BOTTOM_NAV_INCLUDED')) {
    define('FACULTY_BOTTOM_NAV_INCLUDED', true);
?>
<!-- Bottom Navigation for mobile -->
<nav class="faculty-bottom-nav d-lg-none" aria-label="Faculty quick navigation" role="navigation">
    <div class="bottom-nav-items">
        <a href="<?php echo htmlspecialchars(clean_url($facultyPath . '/dashboard.php', $facultyBasePath)); ?>" 
           class="bottom-nav-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" 
           aria-label="Dashboard Home"
           aria-current="<?php echo $currentPage === 'dashboard.php' ? 'page' : 'false'; ?>">
            <span class="nav-icon-wrapper">
                <span class="nav-icon"><i class="fas fa-th-large"></i></span>
                <span class="nav-ripple"></span>
            </span>
            <span class="nav-label">Home</span>
        </a>
        <a href="<?php echo htmlspecialchars(clean_url($facultyPath . '/requirements.php', $facultyBasePath)); ?>" 
           class="bottom-nav-item <?php echo $currentPage === 'requirements.php' ? 'active' : ''; ?>" 
           aria-label="Requirements Tasks"
           aria-current="<?php echo $currentPage === 'requirements.php' ? 'page' : 'false'; ?>">
            <span class="nav-icon-wrapper">
                <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                <span class="nav-ripple"></span>
            </span>
            <span class="nav-label">Tasks</span>
        </a>
        <a href="<?php echo htmlspecialchars(clean_url($facultyPath . '/submissions.php', $facultyBasePath)); ?>" 
           class="bottom-nav-item <?php echo $currentPage === 'submissions.php' ? 'active' : ''; ?>" 
           aria-label="My Submissions"
           aria-current="<?php echo $currentPage === 'submissions.php' ? 'page' : 'false'; ?>">
            <span class="nav-icon-wrapper">
                <span class="nav-icon"><i class="fas fa-upload"></i></span>
                <span class="nav-ripple"></span>
            </span>
            <span class="nav-label">Submissions</span>
        </a>
        <a href="<?php echo htmlspecialchars(clean_url($facultyPath . '/profile.php', $facultyBasePath)); ?>" 
           class="bottom-nav-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" 
           aria-label="My Profile"
           aria-current="<?php echo $currentPage === 'profile.php' ? 'page' : 'false'; ?>">
            <span class="nav-icon-wrapper">
                <span class="nav-icon"><i class="fas fa-user"></i></span>
                <span class="nav-ripple"></span>
            </span>
            <span class="nav-label">Profile</span>
        </a>
    </div>
</nav>
<?php } ?>

<?php if ($isDean || $hasPardonOpenerAssignments || $isTarfEndorserTarget || $isTarfFundAvailabilityActor || $isTarfPresidentViewer): ?>
<script>
(function() {
    var apiUrl = '<?php echo htmlspecialchars(clean_url($facultyPath . '/faculty_scope_activity_counts_api.php', $facultyBasePath)); ?>';
    var POLL_INTERVAL_MS = 30000;

    function updateBadges(counts) {
        if (!counts) return;
        document.querySelectorAll('.scope-activity-badge').forEach(function(badge) {
            var key = badge.getAttribute('data-count-key');
            var n = counts[key] || 0;
            if (n > 0) {
                badge.textContent = n > 99 ? '99+' : String(n);
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        });
    }

    function fetchCounts() {
        fetch(apiUrl, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.counts) {
                    updateBadges(data.counts);
                }
            })
            .catch(function() {});
    }

    fetchCounts();
    setInterval(fetchCounts, POLL_INTERVAL_MS);
})();
</script>
<?php endif; ?>

<?php
// Include chat bubble for faculty-admin communication
require_once __DIR__ . '/../includes/chat_bubble.php';
?>
