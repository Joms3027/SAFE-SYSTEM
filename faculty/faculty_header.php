<?php
// Enhanced faculty header with responsive, app-like UX
$userName = trim($_SESSION['user_name'] ?? 'Faculty Member');
$userRole = $_SESSION['user_role'] ?? 'Faculty';
$userFirstName = explode(' ', $userName)[0] ?? $userName;
$userInitial = strtoupper(substr($userName, 0, 1));

try {
    $currentDate = new DateTime('now', new DateTimeZone('Asia/Manila'));
} catch (Exception $e) {
    $currentDate = new DateTime();
}
$dayLabel = $currentDate->format('D, M j');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <meta name="description" content="WPU Faculty and Staff Management System">
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset_url('logo.png', true); ?>">
</head>
<body>
<div class="faculty-app-header">
    <div class="header-left">
        <button class="menu-toggle" type="button" id="sidebarToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Toggle navigation menu">
            <span class="sr-only">Toggle sidebar</span>
            <i class="fas fa-bars"></i>
        </button>
        <div class="brand">
            <div class="brand-icon">
                <i class="fas fa-university"></i>
            </div>
            <div class="brand-copy">
                <span class="brand-title">WPU Safe-System Portal</span>
                <span class="brand-meta"><?php echo htmlspecialchars($dayLabel); ?> · Western Philippines University</span>
            </div>
        </div>
    </div>
    <div class="header-actions">
        <div class="quick-links d-none d-md-flex">
            <a href="dashboard.php" class="header-link" title="Dashboard">
                <i class="fas fa-gauge"></i><span>Dashboard</span>
            </a>
            <a href="requirements.php" class="header-link" title="Requirements">
                <i class="fas fa-clipboard-list"></i><span>Requirements</span>
            </a>
            <a href="pds.php" class="header-link" title="Personal Data Sheet">
                <i class="fas fa-id-card"></i><span>PDS</span>
            </a>
        </div>
        <div class="header-divider d-none d-lg-block"></div>
        <?php require_once __DIR__ . '/../includes/notification_bell.php'; ?>
        <a href="profile.php" class="header-profile" title="View profile">
            <span class="header-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($userInitial); ?></span>
            <span class="header-profile-details d-none d-sm-flex">
                <span class="header-profile-name"><?php echo htmlspecialchars($userFirstName); ?></span>
                <span class="header-profile-role"><?php echo htmlspecialchars($userRole); ?></span>
            </span>
        </a>
    </div>
</div>

<!-- Mobile context bar -->
<div class="header-mobile-context d-md-none">
    <span class="context-chip">
        <i class="fas fa-user-graduate"></i><?php echo htmlspecialchars($userRole); ?>
    </span>
    <span class="context-chip">
        <i class="fas fa-calendar-day"></i><?php echo htmlspecialchars($dayLabel); ?>
    </span>
</div>

<!-- Mobile Sidebar Overlay -->
<div id="sidebar-overlay" class="d-none" onclick="toggleSidebar()"></div>

<style>
:root {
    --faculty-header-height: 66px;
    --faculty-header-bg: linear-gradient(135deg, rgba(9, 24, 45, 0.94) 0%, rgba(28, 70, 140, 0.94) 68%, rgba(59, 130, 246, 0.88) 100%);
    --faculty-header-border: rgba(148, 163, 184, 0.28);
    --faculty-header-shadow: 0 20px 32px rgba(15, 23, 42, 0.22);
}

.faculty-app-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: var(--faculty-header-height);
    padding: 0 1.1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    background: var(--faculty-header-bg);
    color: #f8fafc;
    border-bottom: 1px solid var(--faculty-header-border);
    box-shadow: var(--faculty-header-shadow);
    backdrop-filter: blur(14px);
    z-index: 1040;
}

.faculty-app-header a {
    color: inherit;
    text-decoration: none;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    min-width: 0;
}

.menu-toggle {
    width: 44px;
    height: 44px;
    border: none;
    border-radius: 14px;
    background: rgba(148, 163, 184, 0.12);
    color: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    transition: transform 0.25s ease, background 0.25s ease, box-shadow 0.25s ease;
}

/* Hover effect removed for faculty pages */

.menu-toggle:active {
    transform: scale(0.96);
}

.brand {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

.brand-icon {
    width: 38px;
    height: 38px;
    border-radius: 14px;
    background: rgba(59, 130, 246, 0.18);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: #cfe3ff;
    box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.22), 0 12px 20px rgba(59, 130, 246, 0.28);
}

.brand-copy {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
}

.brand-title {
    font-size: 1.18rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.brand-meta {
    font-size: 0.72rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(226, 232, 240, 0.92);
    white-space: nowrap;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.9rem;
}

.quick-links {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.35rem 0.45rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.28);
    border: 1px solid rgba(148, 163, 184, 0.28);
}

.header-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.9rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
    color: rgba(226, 232, 240, 0.9);
    transition: background 0.2s ease, transform 0.2s ease, color 0.2s ease;
}

.header-link:focus-visible {
    background: rgba(248, 250, 252, 0.2);
    color: #ffffff;
    transform: translateY(-1px);
}

.header-divider {
    width: 1px;
    height: 32px;
    background: linear-gradient(180deg, rgba(148, 163, 184, 0) 0%, rgba(226, 232, 240, 0.65) 48%, rgba(148, 163, 184, 0) 100%);
}

.header-profile {
    display: inline-flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.4rem 0.45rem;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(248, 250, 252, 0.08);
    transition: transform 0.24s ease, background 0.24s ease, box-shadow 0.24s ease;
}

.header-profile:focus-visible {
    transform: translateY(-1px);
    background: rgba(248, 250, 252, 0.16);
    box-shadow: 0 18px 26px rgba(59, 130, 246, 0.2);
}

.header-profile-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    letter-spacing: 0.02em;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.92) 0%, rgba(37, 99, 235, 0.85) 100%);
    color: #f8fafc;
    text-transform: uppercase;
    box-shadow: 0 10px 20px rgba(59, 130, 246, 0.22);
}

.header-profile-details {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
}

.header-profile-name {
    font-size: 0.86rem;
    font-weight: 700;
}

.header-profile-role {
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(226, 232, 240, 0.75);
}

.header-mobile-context {
    position: fixed;
    top: var(--faculty-header-height);
    left: 0;
    right: 0;
    display: flex;
    gap: 0.55rem;
    padding: 0.45rem 1rem;
    background: rgba(15, 34, 64, 0.92);
    color: rgba(226, 232, 240, 0.94);
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
    backdrop-filter: blur(12px);
    z-index: 1035;
}

.context-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(59, 130, 246, 0.22);
    font-size: 0.74rem;
    font-weight: 600;
}

#sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(8, 20, 40, 0.65);
    backdrop-filter: blur(6px);
    z-index: 1029;
    transition: opacity 0.3s ease;
}

#sidebar-overlay.d-none {
    display: none !important;
}

@media (min-width: 992px) {
    .faculty-app-header {
        left: 278px;
        width: calc(100% - 278px);
        padding: 0 1.75rem;
        border-radius: 0 0 24px 24px;
    }

    .menu-toggle {
        display: none;
    }

    .header-mobile-context {
        display: none;
    }

    .header-profile {
        padding: 0.48rem 0.6rem;
    }
}

@media (max-width: 991.98px) {
    .quick-links {
        background: rgba(15, 34, 64, 0.65);
        border-width: 0;
    }
}

@media (max-width: 767.98px) {
    :root {
        --faculty-header-height: 60px;
    }

    .faculty-app-header {
        padding: 0 0.85rem;
    }

    .brand-title {
        font-size: 1.05rem;
    }

    .brand-meta {
        font-size: 0.68rem;
        letter-spacing: 0.12em;
    }

    .header-actions {
        gap: 0.6rem;
    }

    .header-profile {
        padding: 0.32rem;
        border-radius: 16px;
    }

    .header-profile-avatar {
        width: 32px;
        height: 32px;
        font-size: 0.92rem;
    }

    .context-chip {
        font-size: 0.72rem;
    }
}

@media (max-width: 575.98px) {
    .faculty-app-header {
        padding: 0 0.75rem;
    }

    .brand {
        gap: 0.6rem;
    }

    .brand-icon {
        width: 34px;
        height: 34px;
        font-size: 1.2rem;
    }

    .header-mobile-context {
        padding: 0.4rem 0.85rem;
        gap: 0.45rem;
    }

    .context-chip {
        padding: 0.32rem 0.65rem;
    }
}

@supports (padding: max(0px)) {
    .faculty-app-header {
        padding-left: max(1rem, env(safe-area-inset-left));
        padding-right: max(1rem, env(safe-area-inset-right));
        padding-top: max(0.35rem, env(safe-area-inset-top));
        padding-bottom: max(0.35rem, env(safe-area-inset-bottom) / 2);
    }

    .header-mobile-context {
        padding-left: max(0.9rem, env(safe-area-inset-left));
        padding-right: max(0.9rem, env(safe-area-inset-right));
    }
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}
</style>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggle = document.getElementById('sidebarToggle');

    if (!sidebar || !overlay || !toggle) {
        return;
    }

    const isOpen = sidebar.classList.contains('show');

    if (isOpen) {
        sidebar.classList.remove('show');
        overlay.classList.add('d-none');
        overlay.style.opacity = '';
        toggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    } else {
        sidebar.classList.add('show');
        overlay.classList.remove('d-none');
        overlay.style.opacity = '1';
        toggle.setAttribute('aria-expanded', 'true');
        if (window.innerWidth < 992) {
            document.body.style.overflow = 'hidden';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebar-overlay');

    if (toggle) {
        toggle.addEventListener('click', toggleSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', function(event) {
            if (event.target === overlay) {
                toggleSidebar();
            }
        });
    }

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth >= 992) {
                document.body.style.overflow = '';
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.classList.remove('show');
                }
                if (overlay) {
                    overlay.classList.add('d-none');
                    overlay.style.opacity = '';
                }
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            }
        }, 200);
    });
});
</script>

<?php
// Include chat bubble for faculty-admin communication
require_once __DIR__ . '/../includes/chat_bubble.php';
?>
</body>
</html>