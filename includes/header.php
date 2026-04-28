<?php
/**
 * Header component shown at the top of the main content area
 */

$brandTitle = 'WPU Safe System';
$brandSubtitle = 'Empowering Education';

if (isset($role) && ($role === 'faculty' || $role === 'staff')) {
    $brandTitle = 'WPU Safe-System Portal';
    $brandSubtitle = 'Faculty & Staff Workspace';
} elseif (isset($role) && ($role === 'admin' || $role === 'super_admin')) {
    $brandSubtitle = $role === 'super_admin' ? 'Super Administrator Console' : 'Administrator Console';
}

$layoutClass = '';
if (isset($role) && ($role === 'faculty' || $role === 'staff')) {
    $layoutClass = 'layout-faculty';
} elseif (isset($role) && ($role === 'admin' || $role === 'super_admin')) {
    $layoutClass = 'layout-admin';
}

// Build URLs for user dropdown (getBasePath/clean_url available when header is included)
$basePath = function_exists('getBasePath') ? getBasePath() : '';
$logoutUrl = $basePath ? (function_exists('clean_url') ? clean_url($basePath . '/logout.php', $basePath) : $basePath . '/logout.php') : '../logout.php';
$isAdmin = isset($role) && ($role === 'admin' || $role === 'super_admin');
$dashboardUrl = $isAdmin ? ($basePath . '/admin/dashboard.php') : ($basePath . '/faculty/dashboard.php');
$profileUrl = $isAdmin ? null : ($basePath . '/faculty/profile.php');
$changePasswordUrl = $isAdmin ? null : ($basePath . '/faculty/change_password.php');
if ($profileUrl && function_exists('clean_url')) {
    $profileUrl = clean_url($profileUrl, $basePath);
}
if ($changePasswordUrl && function_exists('clean_url')) {
    $changePasswordUrl = clean_url($changePasswordUrl, $basePath);
}
$dashboardUrl = $basePath && function_exists('clean_url') ? clean_url($dashboardUrl, $basePath) : $dashboardUrl;
?>

<header class="header">
    <!-- Menu toggle for mobile/tablet -->
    <button class="menu-toggle d-lg-none" 
            type="button" 
            aria-controls="sidebar"
            aria-label="Toggle navigation"
            aria-expanded="false">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Brand -->
    <div class="brand">
        <span class="brand-text">
            <?php echo htmlspecialchars($brandTitle); ?>
            <small><?php echo htmlspecialchars($brandSubtitle); ?></small>
        </span>
    </div>

    <!-- User / Logout (always available) -->
    <div class="ms-auto d-flex align-items-center header-actions">
        <!-- Enhanced Notification Bell -->
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php include_once dirname(__FILE__) . '/notification_bell.php'; ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['user_name'])): ?>
            <div class="dropdown user-dropdown-wrapper">
                <a class="user-dropdown-toggle nav-link dropdown-toggle" 
                   href="#" 
                   id="userDropdown" 
                   role="button" 
                   data-bs-toggle="dropdown" 
                   aria-expanded="false"
                   aria-haspopup="true"
                   aria-label="Open user menu">
                    <i class="fas fa-user-circle" aria-hidden="true"></i>
                    <span class="user-name d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <i class="fas fa-chevron-down user-dropdown-chevron d-none d-sm-inline" aria-hidden="true"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end user-dropdown-menu" aria-labelledby="userDropdown">
                    <li class="dropdown-header user-dropdown-header">
                        <div class="user-dropdown-profile-card">
                            <div class="user-avatar-small">
                                <i class="fas fa-user-circle" aria-hidden="true"></i>
                            </div>
                            <div class="user-info">
                                <div class="user-name-small"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                                <div class="user-role-small">
                                    <?php 
                                    if (isset($role)) {
                                        echo ($role === 'admin' || $role === 'super_admin') ? ($role === 'super_admin' ? 'Super Administrator' : 'Administrator') : ($role === 'staff' ? 'Staff Member' : 'Faculty Member');
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li class="user-dropdown-section">
                        <div class="dropdown-section-label">Quick actions</div>
                        <a class="dropdown-item user-dropdown-item" href="<?php echo htmlspecialchars($dashboardUrl); ?>">
                            <i class="fas fa-th-large me-2" aria-hidden="true"></i>
                            <span>Dashboard</span>
                        </a>
                        <?php if ($profileUrl): ?>
                        <a class="dropdown-item user-dropdown-item" href="<?php echo htmlspecialchars($profileUrl); ?>">
                            <i class="fas fa-user me-2" aria-hidden="true"></i>
                            <span>My Profile</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($changePasswordUrl): ?>
                        <a class="dropdown-item user-dropdown-item" href="<?php echo htmlspecialchars($changePasswordUrl); ?>">
                            <i class="fas fa-key me-2" aria-hidden="true"></i>
                            <span>Change Password</span>
                        </a>
                        <?php endif; ?>
                    </li>
                    <li><hr class="dropdown-divider user-dropdown-logout-divider"></li>
                    <li>
                        <a class="dropdown-item user-dropdown-item user-dropdown-logout" 
                           href="<?php echo htmlspecialchars($logoutUrl); ?>" 
                           onclick="event.preventDefault(); if(typeof confirmLogout === 'function') { confirmLogout(this); } else { if(confirm('Are you sure you want to logout?')) { window.location.href = this.getAttribute('data-logout-url') || '<?php echo htmlspecialchars($logoutUrl); ?>'; } } return false;" 
                           data-logout-url="<?php echo htmlspecialchars($logoutUrl); ?>">
                            <i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        <?php else: ?>
            <a class="btn btn-primary btn-sm ms-2" href="login.php">Login</a>
        <?php endif; ?>
    </div>
</header>

<!-- Sidebar Overlay (for mobile/tablet) -->
<div class="sidebar-overlay d-lg-none" id="sidebar-overlay"></div>

<script>
(function(roleClass) {
    if (!roleClass) return;
    // Wait for body to be available - use DOMContentLoaded or check if body exists
    function addRoleClass() {
        var body = document.body;
        if (body && body.classList && !body.classList.contains(roleClass)) {
            body.classList.add(roleClass);
        }
    }
    
    // If body already exists, add class immediately
    if (document.body) {
        addRoleClass();
    } else {
        // Otherwise wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', addRoleClass);
        } else {
            // DOM already loaded, try again
            setTimeout(addRoleClass, 0);
        }
    }
})(<?php echo json_encode($layoutClass); ?>);
</script>