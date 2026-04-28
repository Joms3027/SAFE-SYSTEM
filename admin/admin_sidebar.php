<?php
$currentPage = basename($_SERVER['PHP_SELF']);

// CRITICAL: Get base path for absolute URLs - fixes PWA navigation issues on mobile
$adminBasePath = '';
if (function_exists('getBasePath')) {
    $adminBasePath = getBasePath();
} else {
    // Fallback: calculate from script location
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($scriptName) {
        $scriptDir = dirname($scriptName);
        if (strpos($scriptDir, '/admin') !== false) {
            $adminBasePath = substr($scriptDir, 0, strpos($scriptDir, '/admin'));
        }
    }
}
// Normalize: ensure it starts with / and doesn't end with /
if ($adminBasePath && $adminBasePath !== '/') {
    $adminBasePath = '/' . trim($adminBasePath, '/');
} elseif ($adminBasePath === '/') {
    $adminBasePath = '';
}
// When loaded from HR_EVENT/admin/, use project root for main admin links (Dashboard, Employee, etc.)
// so they point to /admin/ not /HR_EVENT/admin/
$rootPath = (strpos($adminBasePath, '/HR_EVENT') !== false)
    ? preg_replace('#/HR_EVENT.*$#', '', $adminBasePath) ?: ''
    : $adminBasePath;
$adminPath = $rootPath ? $rootPath . '/admin' : '/admin';

// Attempt to resolve the admin profile picture for sidebar display (if admin has profile picture support in future)
$profile_picture = '';
$user_name = $_SESSION['user_name'] ?? 'Administrator';
$user_email = $_SESSION['user_email'] ?? '';
?>
<nav id="sidebar" class="sidebar office-sidebar" role="navigation" aria-label="Admin navigation">
    
    <!-- User Profile Card -->
    <div class="sidebar-user-card">
        <div class="user-avatar-wrapper">
            <?php if (!empty($profile_picture)): ?>
                <img src="../uploads/profiles/<?php echo htmlspecialchars($profile_picture); ?>?t=<?php echo time(); ?>" alt="Profile Picture" class="user-avatar-img">
            <?php else: ?>
                <div class="user-avatar-circle">
                    <?php 
                    $initials = '';
                    $nameParts = explode(' ', $user_name);
                    foreach ($nameParts as $part) {
                        $initials .= strtoupper(substr($part, 0, 1));
                    }
                    echo substr($initials, 0, 2);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="user-details">
            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
            <div class="user-role"><?php
                $ut = $_SESSION['user_type'] ?? '';
                echo $ut === 'super_admin' ? 'Super Administrator' : ($ut === 'admin' ? 'Administrator' : ($ut === 'staff' ? 'Staff Member' : 'Faculty Member'));
            ?></div>
        </div>
    </div>
    
    <div class="sidebar-scroll">
        <nav class="sidebar-navigation">
            <!-- Main Section -->
            <div class="nav-section">
                <div class="nav-section-label">Overview</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/dashboard.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-th-large"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/analytics.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                            <span class="nav-text">Analytics</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Management Section -->
            <div class="nav-section">
                <div class="nav-section-label">Management</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'faculty.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/faculty.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span class="nav-text">Employee</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'positions.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/positions.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-briefcase"></i></span>
                            <span class="nav-text">Positions & Salary</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'requirements.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/requirements.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-tasks"></i></span>
                            <span class="nav-text">Requirements</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'pds_review.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/pds_review.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                            <span class="nav-text">PDS Review</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'submissions.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/submissions.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                            <span class="nav-text">Submissions</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/attendance.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-user-clock"></i></span>
                            <span class="nav-text">Attendance / Entries</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'employee_logs.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/employee_logs.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Payroll Management</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'dtr_submissions.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/dtr_submissions.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clipboard-check"></i></span>
                            <span class="nav-text">DTR Submissions</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'employees_dtr.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/employees_dtr.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Employees DTR</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'pardon_requests.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/pardon_requests.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-gavel"></i></span>
                            <span class="nav-text">Pardon Requests<?php echo isSuperAdmin() ? '' : ' <span class="badge bg-info" style="font-size: 0.65rem;">View Only</span>'; ?></span>
                            <?php
                            // Show badge with pending count (use __DIR__ so path works when sidebar is included from HR_EVENT/admin/)
                            try {
                                require_once __DIR__ . '/../includes/database.php';
                                $database = Database::getInstance();
                                $db = $database->getConnection();
                                $stmt = $db->query("SELECT COUNT(*) as count FROM pardon_requests WHERE status = 'pending'");
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $pendingCount = $result['count'] ?? 0;
                                if ($pendingCount > 0) {
                                    echo '<span class="badge bg-warning ms-2">' . $pendingCount . '</span>';
                                }
                            } catch (Exception $e) {
                                // Silently fail if database error
                            }
                            ?>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'official_time_requests.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/official_time_requests.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clock"></i></span>
                            <span class="nav-text">Official Time Requests</span>
                            <?php
                            try {
                                if (!isset($db)) { require_once __DIR__ . '/../includes/database.php'; $database = Database::getInstance(); $db = $database->getConnection(); }
                                $stmt = $db->query("SELECT COUNT(DISTINCT employee_id) as count FROM official_time_requests WHERE status = 'pending_super_admin'");
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $otrCount = $result['count'] ?? 0;
                                if ($otrCount > 0) {
                                    echo '<span class="badge bg-warning ms-2">' . (int)$otrCount . '</span>';
                                }
                            } catch (Exception $e) { }
                            ?>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'tarf_requests.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/tarf_requests.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-plane-departure"></i></span>
                            <span class="nav-text">TARF requests</span>
                            <?php
                            try {
                                if (!isset($db)) { require_once __DIR__ . '/../includes/database.php'; $database = Database::getInstance(); $db = $database->getConnection(); }
                                $tarfTbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
                                if ($tarfTbl && $tarfTbl->rowCount() > 0) {
                                    $stmt = $db->query(
                                        "SELECT COUNT(*) FROM tarf_requests WHERE status IN ('pending_joint','pending_supervisor','pending_endorser','pending_president','pending')"
                                    );
                                    $tarfPending = $stmt ? (int) $stmt->fetchColumn() : 0;
                                    if ($tarfPending > 0) {
                                        echo '<span class="badge bg-warning ms-2">' . $tarfPending . '</span>';
                                    }
                                }
                            } catch (Exception $e) { }
                            ?>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'station_management.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/station_management.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-clock"></i></span>
                            <span class="nav-text">Station Management</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentPage === 'events.php' && strpos($_SERVER['PHP_SELF'] ?? '', 'HR_EVENT') !== false) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($rootPath . '/HR_EVENT/admin/events.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">HR Events</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Communication Section -->
            <div class="nav-section">
                <div class="nav-section-label">Communication</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'announcements.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/announcements.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-bullhorn"></i></span>
                            <span class="nav-text">Announcements</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'calendar.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/calendar.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                            <span class="nav-text">Calendar</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'feedback.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/feedback.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-comment-dots"></i></span>
                            <span class="nav-text">Employee feedback</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- System Section -->
            <div class="nav-section">
                <div class="nav-section-label">System</div>
                <ul class="nav-items">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/settings.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span class="nav-text">Settings</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'system_logs.php' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(clean_url($adminPath . '/system_logs.php', $rootPath)); ?>">
                            <span class="nav-icon"><i class="fas fa-history"></i></span>
                            <span class="nav-text">System Logs</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
    
    <div class="sidebar-footer">
        <a class="sidebar-logout-btn" href="<?php echo htmlspecialchars(clean_url($rootPath . '/logout.php', $rootPath)); ?>" data-logout-url="<?php echo htmlspecialchars(clean_url($rootPath . '/logout.php', $rootPath)); ?>" onclick="event.preventDefault(); if(typeof confirmLogout === 'function') { confirmLogout(this); } else { if(confirm('Are you sure you want to logout?')) { window.location.href = this.getAttribute('data-logout-url') || '<?php echo htmlspecialchars(clean_url($rootPath . '/logout.php', $rootPath)); ?>'; } } return false;">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="nav-text">Sign Out</span>
        </a>
    </div>
</nav>

<script>
(function() {
    var scrollEl = document.querySelector('.office-sidebar .sidebar-scroll');
    if (!scrollEl) return;
    function saveScroll() {
        sessionStorage.setItem('adminSidebarScroll', String(scrollEl.scrollTop));
    }
    function restoreScroll() {
        var saved = sessionStorage.getItem('adminSidebarScroll');
        if (saved !== null) {
            var pos = parseInt(saved, 10);
            if (pos > 0) {
                scrollEl.scrollTop = pos;
            }
            sessionStorage.removeItem('adminSidebarScroll');
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
// Include chat bubble for admin-faculty communication
require_once __DIR__ . '/../includes/chat_bubble.php';
?>