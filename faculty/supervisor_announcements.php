<?php
/**
 * Supervisor Announcements - For pardon openers to create and manage announcements to employees in their scope.
 * Sends notifications and emails to scope employees.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAuth();

if (isset($_GET['created']) && $_GET['created'] === '1') {
    $_SESSION['success'] = 'Announcement created successfully.';
    $bp = getBasePath();
    redirect(clean_url($bp . '/faculty/supervisor_announcements.php', $bp));
}

$database = Database::getInstance();
$db = $database->getConnection();

if (!hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
    $_SESSION['error'] = 'This page is for supervisors with pardon opener assignments.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
}

$basePath = getBasePath();
$apiUrl = $basePath . '/faculty/supervisor_announcements_api.php';

// Load supervisor's announcements server-side (fallback when API fails)
$announcements = [];
try {
    $hasTargetAudience = false;
    $checkCol = $db->query("SHOW COLUMNS FROM supervisor_announcements LIKE 'target_audience'");
    if ($checkCol && $checkCol->rowCount() > 0) $hasTargetAudience = true;
    $cols = $hasTargetAudience
        ? 'sa.id, sa.title, sa.content, sa.priority, sa.target_audience, sa.is_active, sa.expires_at, sa.created_at'
        : 'sa.id, sa.title, sa.content, sa.priority, sa.is_active, sa.expires_at, sa.created_at';
    $stmt = $db->prepare("
        SELECT $cols, u.first_name, u.last_name
        FROM supervisor_announcements sa
        JOIN users u ON sa.supervisor_id = u.id
        WHERE sa.supervisor_id = ?
        ORDER BY sa.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($announcements as &$a) { if (!isset($a['target_audience'])) $a['target_audience'] = 'all'; }
} catch (Exception $e) {
    error_log("Supervisor announcements load error: " . $e->getMessage());
}

// Load employment statuses for Display to dropdown (Faculty/Staff + status combinations)
$employmentStatuses = [];
try {
    $empStmt = $db->prepare("SELECT id, name FROM employment_statuses ORDER BY name");
    $empStmt->execute();
    $employmentStatuses = $empStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/navigation.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announce to My Team - WPU Faculty System</title>
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
                    <div class="page-title"><i class="fas fa-bullhorn me-2"></i>Announcement</div>
                    <p class="page-subtitle text-muted">Create announcements for employees in your scope. They will receive in-app notifications and email.</p>
                </div>

                <?php displayMessage(); ?>

                <div class="mb-4">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
                        <i class="fas fa-plus me-1"></i>Create Announcement
                    </button>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>My Announcements</h5>
                    </div>
                    <div class="card-body">
                        <div id="announcementsList">
                            <?php
                            if (!empty($announcements)):
                                foreach ($announcements as $a):
                                    $priorityClass = ($a['priority'] ?? '') === 'urgent' ? 'border-danger' : (($a['priority'] ?? '') === 'high' ? 'border-warning' : '');
                                    $expires = !empty($a['expires_at']) ? 'Expires: ' . date('M j, Y g:i A', strtotime($a['expires_at'])) : '';
                                    $audienceLabel = 'All';
                                    $targetAud = trim($a['target_audience'] ?? 'all');
                                    if ($targetAud && $targetAud !== 'all') {
                                        $selections = array_map('trim', explode(',', $targetAud));
                                        $labels = [];
                                        foreach ($selections as $sel) {
                                            $p = explode('|', $sel, 2);
                                            if (count($p) === 2) {
                                                $labels[] = ($p[0] === 'faculty' ? 'Faculty' : 'Staff') . ' (' . htmlspecialchars($p[1]) . ')';
                                            }
                                        }
                                        $audienceLabel = $labels ? implode(', ', $labels) : $targetAud;
                                    }
                                    $badgeTitle = strlen($audienceLabel) > 40 ? ' title="' . htmlspecialchars($audienceLabel) . '"' : '';
                                    $badgeText = strlen($audienceLabel) > 40 ? substr($audienceLabel, 0, 37) . '...' : $audienceLabel;
                                    $contentPreview = htmlspecialchars(substr($a['content'], 0, 150)) . (strlen($a['content']) > 150 ? '...' : '');
                                    $createdAt = !empty($a['created_at']) ? date('M j, Y g:i A', strtotime($a['created_at'])) : '';
                            ?>
                            <div class="card mb-3 announcement-card <?php echo $priorityClass; ?>" data-id="<?php echo (int)$a['id']; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($a['title']); ?> <span class="badge bg-secondary"<?php echo $badgeTitle; ?>><?php echo htmlspecialchars($badgeText); ?></span></h6>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-danger btn-delete-ann" data-id="<?php echo (int)$a['id']; ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                    <p class="card-text small text-muted mb-1"><?php echo $contentPreview; ?></p>
                                    <small class="text-muted"><?php echo $createdAt; ?><?php echo $expires ? ' &middot; ' . $expires : ''; ?></small>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div id="announcementsLoading" class="text-center py-4 d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 mb-0 text-muted">Loading...</p>
                        </div>
                        <div id="announcementsEmpty" class="text-center py-5 <?php echo empty($announcements) ? '' : 'd-none'; ?>">
                            <i class="fas fa-bullhorn fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No announcements yet. Create one to notify your team.</p>
                        </div>
                        <div id="announcementsError" class="alert alert-warning py-2 d-none" role="alert">
                            <i class="fas fa-exclamation-triangle me-1"></i>Failed to refresh. <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="announcementsRetryBtn">Retry</button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Create Announcement Modal -->
    <div class="modal fade" id="createAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Create Announcement</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createAnnouncementForm">
                        <div class="mb-3">
                            <label class="form-label" for="annTitle">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="annTitle" name="title" required maxlength="255" placeholder="Announcement title">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="annContent">Content <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="annContent" name="content" rows="4" required placeholder="Write your announcement..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Display to</label>
                            <div class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="annTargetAll" value="all">
                                    <label class="form-check-label fw-semibold" for="annTargetAll">All (Faculty &amp; Staff)</label>
                                </div>
                                <hr class="my-2">
                                <?php foreach ($employmentStatuses as $es): ?>
                                <div class="form-check">
                                    <input class="form-check-input ann-target-option" type="checkbox" id="annTarget_faculty_<?php echo (int)$es['id']; ?>" value="faculty|<?php echo htmlspecialchars($es['name']); ?>">
                                    <label class="form-check-label" for="annTarget_faculty_<?php echo (int)$es['id']; ?>">Faculty (<?php echo htmlspecialchars($es['name']); ?>)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input ann-target-option" type="checkbox" id="annTarget_staff_<?php echo (int)$es['id']; ?>" value="staff|<?php echo htmlspecialchars($es['name']); ?>">
                                    <label class="form-check-label" for="annTarget_staff_<?php echo (int)$es['id']; ?>">Staff (<?php echo htmlspecialchars($es['name']); ?>)</label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Select one or more. Leave all unchecked for All.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="annPriority">Priority</label>
                                <select class="form-select" id="annPriority" name="priority">
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="low">Low</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="annExpires">Expires (optional)</label>
                                <input type="datetime-local" class="form-control" id="annExpires" name="expires_at">
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="annSendNotifications" name="send_notifications" value="1" checked>
                            <label class="form-check-label" for="annSendNotifications">Send notifications and emails to employees in my scope</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitAnnouncementBtn">
                        <i class="fas fa-paper-plane me-1"></i>Post Announcement
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script>
    (function() {
        var apiUrl = <?php echo json_encode($apiUrl); ?>;
        var hasServerContent = <?php echo !empty($announcements) ? 'true' : 'false'; ?>;
        var listEl = document.getElementById('announcementsList');
        var loadingEl = document.getElementById('announcementsLoading');
        var emptyEl = document.getElementById('announcementsEmpty');
        var errorEl = document.getElementById('announcementsError');

        function bindDeleteButtons() {
            listEl.querySelectorAll('.btn-delete-ann').forEach(function(btn) {
                btn.onclick = function() {
                    if (confirm('Delete this announcement?')) deleteAnnouncement(this.getAttribute('data-id'));
                };
            });
        }

        function loadAnnouncements() {
            errorEl.classList.add('d-none');
            var hadContent = listEl.children.length > 0;
            if (!hadContent) {
                loadingEl.classList.remove('d-none');
                listEl.innerHTML = '';
                emptyEl.classList.add('d-none');
            }
            fetch(apiUrl + '?action=list', { credentials: 'same-origin' })
                .then(function(r) {
                    if (!r.ok) throw new Error('API returned ' + r.status);
                    return r.json();
                })
                .then(function(data) {
                    loadingEl.classList.add('d-none');
                    listEl.innerHTML = '';
                    if (data.success && data.announcements && data.announcements.length > 0) {
                        data.announcements.forEach(function(a) {
                            var card = document.createElement('div');
                            card.className = 'card mb-3 announcement-card';
                            var priorityClass = a.priority === 'urgent' ? 'border-danger' : (a.priority === 'high' ? 'border-warning' : '');
                            card.classList.add(priorityClass);
                            card.setAttribute('data-id', a.id);
                            var expires = a.expires_at ? 'Expires: ' + new Date(a.expires_at).toLocaleString() : '';
                            var audienceLabel = 'All';
                            if (a.target_audience && a.target_audience !== 'all') {
                                var selections = a.target_audience.split(',');
                                var labels = [];
                                selections.forEach(function(sel) {
                                    var p = sel.trim().split('|');
                                    if (p.length === 2) {
                                        labels.push((p[0] === 'faculty' ? 'Faculty' : 'Staff') + ' (' + escapeHtml(p[1]) + ')');
                                    }
                                });
                                audienceLabel = labels.length > 0 ? labels.join(', ') : a.target_audience;
                            }
                            var badgeTitle = audienceLabel.length > 40 ? ' title="' + escapeHtml(audienceLabel) + '"' : '';
                            card.innerHTML = '<div class="card-body">' +
                                '<div class="d-flex justify-content-between align-items-start">' +
                                '<h6 class="card-title mb-1">' + escapeHtml(a.title) + ' <span class="badge bg-secondary"' + badgeTitle + '>' + (audienceLabel.length > 40 ? audienceLabel.substring(0, 37) + '...' : audienceLabel) + '</span></h6>' +
                                '<div class="btn-group btn-group-sm">' +
                                '<button type="button" class="btn btn-outline-danger btn-delete-ann" data-id="' + a.id + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                                '</div></div>' +
                                '<p class="card-text small text-muted mb-1">' + escapeHtml(a.content.substring(0, 150)) + (a.content.length > 150 ? '...' : '') + '</p>' +
                                '<small class="text-muted">' + new Date(a.created_at).toLocaleString() + (expires ? ' &middot; ' + expires : '') + '</small>' +
                                '</div>';
                            listEl.appendChild(card);
                        });
                        bindDeleteButtons();
                        emptyEl.classList.add('d-none');
                    } else {
                        emptyEl.classList.remove('d-none');
                    }
                })
                .catch(function() {
                    loadingEl.classList.add('d-none');
                    if (listEl.children.length === 0) {
                        emptyEl.classList.remove('d-none');
                        emptyEl.innerHTML = '<p class="text-danger">Failed to load announcements.</p>';
                    } else {
                        errorEl.classList.remove('d-none');
                    }
                });
        }

        var retryBtn = document.getElementById('announcementsRetryBtn');
        if (retryBtn) retryBtn.addEventListener('click', loadAnnouncements);

        bindDeleteButtons();

        function escapeHtml(s) {
            var div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function deleteAnnouncement(id) {
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) location.reload();
                    else alert(data.message || 'Failed to delete');
                });
        }

        // Display to: All checkbox vs specific options
        document.getElementById('annTargetAll').addEventListener('change', function() {
            if (this.checked) {
                document.querySelectorAll('.ann-target-option').forEach(function(cb) { cb.checked = false; });
            }
        });
        document.querySelectorAll('.ann-target-option').forEach(function(cb) {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    document.getElementById('annTargetAll').checked = false;
                }
            });
        });

        function getTargetAudienceValue() {
            if (document.getElementById('annTargetAll').checked) return 'all';
            var selected = [];
            document.querySelectorAll('.ann-target-option:checked').forEach(function(cb) {
                if (cb.value) selected.push(cb.value);
            });
            return selected.length > 0 ? selected.join(',') : 'all';
        }

        document.getElementById('submitAnnouncementBtn').addEventListener('click', function() {
            var form = document.getElementById('createAnnouncementForm');
            var title = document.getElementById('annTitle').value.trim();
            var content = document.getElementById('annContent').value.trim();
            if (!title || !content) {
                alert('Title and content are required.');
                return;
            }
            var btn = this;
            btn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'create');
            fd.append('title', title);
            fd.append('content', content);
            fd.append('target_audience', getTargetAudienceValue());
            fd.append('priority', document.getElementById('annPriority').value);
            var exp = document.getElementById('annExpires').value;
            if (exp) fd.append('expires_at', exp);
            fd.append('send_notifications', document.getElementById('annSendNotifications').checked ? '1' : '0');
            fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('createAnnouncementModal')).hide();
                        form.reset();
                        window.location.href = <?php echo json_encode($basePath . '/faculty/supervisor_announcements.php?created=1'); ?>;
                    } else {
                        alert(data.message || 'Failed to create announcement');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    alert('Request failed. Please try again.');
                });
        });

        if (!hasServerContent) loadAnnouncements();
    })();
    </script>
</body>
</html>
