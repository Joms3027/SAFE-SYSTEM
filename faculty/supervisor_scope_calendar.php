<?php
/**
 * Team Events & Meetings - For pardon openers to add events/meetings for their team.
 * Events appear on the calendar for employees in their scope and notifications/emails are sent.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAuth();

$database = Database::getInstance();
$db = $database->getConnection();

if (!hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
    $_SESSION['error'] = 'This page is for supervisors with pardon opener assignments.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
}

$basePath = getBasePath();
$apiUrl = $basePath . '/faculty/supervisor_calendar_api.php';
$calendarUrl = $basePath . '/faculty/calendar.php';

require_once __DIR__ . '/../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Events & Meetings - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
</head>
<body class="layout-faculty">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title"><i class="fas fa-calendar-plus me-2"></i>Events & Meetings</div>
                    <p class="page-subtitle text-muted">Add events or meetings. They will appear on the calendar and employees will receive notifications and email.</p>
                </div>

                <?php displayMessage(); ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Add Event or Meeting</h5>
                        <a href="<?php echo htmlspecialchars($calendarUrl); ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-calendar-alt me-1"></i>View Calendar
                        </a>
                    </div>
                    <div class="card-body">
                        <form id="createEventForm">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label" for="evTitle">Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="evTitle" name="title" required maxlength="255" placeholder="e.g. Department Meeting">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="evCategory">Category</label>
                                    <select class="form-select" id="evCategory" name="category">
                                        <option value="university_event">Event</option>
                                        <option value="Meeting">Meeting</option>
                                        <option value="Training">Training</option>
                                        <option value="Workshop">Workshop</option>
                                        <option value="Seminar">Seminar</option>
                                        <option value="Conference">Conference</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="evDescription">Description</label>
                                <textarea class="form-control" id="evDescription" name="description" rows="2" placeholder="Optional details..."></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="evDate">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="evDate" name="event_date" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="evTime">Start Time</label>
                                    <input type="time" class="form-control" id="evTime" name="event_time">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="evEndTime">End Time</label>
                                    <input type="time" class="form-control" id="evEndTime" name="end_time">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="evLocation">Location</label>
                                <input type="text" class="form-control" id="evLocation" name="location" placeholder="e.g. Conference Room A">
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="evSendNotifications" name="send_notifications" value="1" checked>
                                <label class="form-check-label" for="evSendNotifications">Send notifications and emails to employees in my scope</label>
                            </div>
                            <button type="submit" class="btn btn-primary" id="submitEventBtn">
                                <i class="fas fa-calendar-plus me-1"></i>Add Event
                            </button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script>
    (function() {
        var apiUrl = <?php echo json_encode($apiUrl); ?>;
        var form = document.getElementById('createEventForm');
        var submitBtn = document.getElementById('submitEventBtn');

        // Set default date to today
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('evDate').value = today;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var title = document.getElementById('evTitle').value.trim();
            var eventDate = document.getElementById('evDate').value;
            if (!title || !eventDate) {
                alert('Title and date are required.');
                return;
            }
            submitBtn.disabled = true;
            var fd = new FormData(form);
            fd.append('action', 'create');
            fetch(apiUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    submitBtn.disabled = false;
                    if (data.success) {
                        alert(data.message);
                        form.reset();
                        document.getElementById('evDate').value = new Date().toISOString().split('T')[0];
                    } else {
                        alert(data.message || 'Failed to create event');
                    }
                })
                .catch(function() {
                    submitBtn.disabled = false;
                    alert('Request failed. Please try again.');
                });
        });
    })();
    </script>
</body>
</html>
