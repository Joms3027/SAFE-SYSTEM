<?php
/**
 * Admin: HR Events - Create events and get event scanner link/QR.
 */
// Debug: show PHP errors when ?debug=1 (must be before any includes)
if (!empty($_GET['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
}

$configPath = __DIR__ . '/../../includes/config.php';
if (!is_file($configPath)) {
    die('HR Events: Config not found. Expected: ' . htmlspecialchars($configPath));
}
require_once $configPath;
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database.php';

requireAdmin();

// Use HR_EVENT base path when this script runs under HR_EVENT (getBasePath() may return project root or already include /HR_EVENT)
$basePath = getBasePath();
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'HR_EVENT') !== false && strpos($basePath, '/HR_EVENT') === false) {
    $basePath = rtrim($basePath, '/') . '/HR_EVENT';
}
$projectBase = (strpos($basePath, '/HR_EVENT') !== false) ? preg_replace('#/HR_EVENT.*$#', '', $basePath) : $basePath;
$database = Database::getInstance();
$db = $database->getConnection();

// Create event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $event_time = trim($_POST['event_time'] ?? '') ?: null;
    $end_time = trim($_POST['end_time'] ?? '') ?: null;
    $location = trim($_POST['location'] ?? '') ?: null;
    if ($title && $event_date) {
        $qr_token = bin2hex(random_bytes(16));
        $created_by = (int) ($_SESSION['user_id'] ?? 0);
        try {
            $stmt = $db->prepare("
                INSERT INTO hr_events (title, description, event_date, event_time, end_time, location, qr_token, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->execute([$title, $description ?: null, $event_date, $event_time, $end_time, $location, $qr_token, $created_by ?: null]);
            $_SESSION['success'] = 'Event created. Open the event scanner at the venue and scan each employee\'s SAFE QR code to check them in.';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Could not create event. ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Title and event date are required.';
    }
    header('Location: ' . $basePath . '/admin/events.php');
    exit;
}

// Get attendance for event (AJAX) - 4-point: IN morning, OUT 12PM, IN 1PM, OUT afternoon
if (isset($_GET['action']) && $_GET['action'] === 'get_attendance' && isset($_GET['event_id'])) {
    header('Content-Type: application/json');
    $eventId = (int) $_GET['event_id'];
    $attendance = [];
    try {
        $stmt = $db->prepare("
            SELECT a.user_id, a.employee_id,
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), a.employee_id, 'Unknown') AS name,
                   MAX(CASE WHEN a.check_type = 'in_morning' THEN a.scanned_at END) AS in_morning,
                   MAX(CASE WHEN a.check_type = 'out_noon' THEN a.scanned_at END) AS out_noon,
                   MAX(CASE WHEN a.check_type = 'in_afternoon' THEN a.scanned_at END) AS in_afternoon,
                   MAX(CASE WHEN a.check_type = 'out_afternoon' THEN a.scanned_at END) AS out_afternoon,
                   MIN(a.scanned_at) AS first_scan
            FROM hr_event_attendances a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.event_id = ?
            GROUP BY a.user_id, a.employee_id, u.first_name, u.last_name
            ORDER BY first_scan ASC
        ");
        $stmt->execute([$eventId]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    echo json_encode(['success' => true, 'attendance' => $attendance]);
    exit;
}

// Export attendance as styled Excel (.xlsx)
if (isset($_GET['action']) && $_GET['action'] === 'export_attendance_xlsx' && isset($_GET['event_id'])) {
    $exportError = function ($message) {
        header('Content-Type: application/json; charset=utf-8');
        header('HTTP/1.1 503 Service Unavailable');
        echo json_encode(['error' => $message]);
        exit;
    };
    $eventId = (int) $_GET['event_id'];
    if ($eventId < 1) {
        $exportError('Invalid event.');
    }
    $eventTitle = 'Event';
    $attendance = [];
    try {
        $stmt = $db->prepare("SELECT title FROM hr_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $eventTitle = $row['title'];
        $stmt = $db->prepare("
            SELECT a.user_id, a.employee_id,
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), a.employee_id, 'Unknown') AS name,
                   MAX(CASE WHEN a.check_type = 'in_morning' THEN a.scanned_at END) AS in_morning,
                   MAX(CASE WHEN a.check_type = 'out_noon' THEN a.scanned_at END) AS out_noon,
                   MAX(CASE WHEN a.check_type = 'in_afternoon' THEN a.scanned_at END) AS in_afternoon,
                   MAX(CASE WHEN a.check_type = 'out_afternoon' THEN a.scanned_at END) AS out_afternoon,
                   MIN(a.scanned_at) AS first_scan
            FROM hr_event_attendances a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.event_id = ?
            GROUP BY a.user_id, a.employee_id, u.first_name, u.last_name
            ORDER BY first_scan ASC
        ");
        $stmt->execute([$eventId]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $exportError('Database error: ' . $e->getMessage());
    }
    $phpSpreadsheetAvailable = false;
    $projectRoot = realpath(__DIR__ . '/../..');
    $autoloadPaths = [
        $projectRoot ? $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' : null,
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
    ];
    $autoloadPaths = array_filter($autoloadPaths);
    foreach ($autoloadPaths as $autoloadPath) {
        if (extension_loaded('zip') && is_file($autoloadPath)) {
            require_once $autoloadPath;
            if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $phpSpreadsheetAvailable = true;
                break;
            }
        }
    }
    if (!$phpSpreadsheetAvailable) {
        // Fallback: output CSV when zip/PhpSpreadsheet not available (e.g. zip extension disabled)
        $filename = 'attendance-' . preg_replace('/[^a-z0-9]/i', '-', $eventTitle) . '-' . date('Y-m-d');
        $filename = trim(preg_replace('/-+/', '-', $filename), '-') ?: 'attendance-' . date('Y-m-d');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Cache-Control: max-age=0');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, ['Event Attendance']);
        fputcsv($out, ['Event:', $eventTitle]);
        fputcsv($out, ['Exported:', date('F j, Y \a\t g:i A')]);
        fputcsv($out, []);
        fputcsv($out, ['#', 'Safe Employee ID', 'Name', 'IN (Morning)', 'OUT (12:00 PM)', 'IN (1:00 PM)', 'OUT (Afternoon)']);
        $num = 1;
        foreach ($attendance as $row) {
            fputcsv($out, [
                $num++,
                $row['employee_id'] ?? '',
                $row['name'] ?? '',
                $row['in_morning'] ? date('g:i A', strtotime($row['in_morning'])) : '—',
                $row['out_noon'] ? date('g:i A', strtotime($row['out_noon'])) : '—',
                $row['in_afternoon'] ? date('g:i A', strtotime($row['in_afternoon'])) : '—',
                $row['out_afternoon'] ? date('g:i A', strtotime($row['out_afternoon'])) : '—',
            ]);
        }
        fclose($out);
        exit;
    }
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheetTitle = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $eventTitle);
    $sheetTitle = trim(mb_substr($sheetTitle, 0, 31));
    $sheet->setTitle($sheetTitle ?: 'Attendance');
    $currentRow = 1;
    // Title row
    $sheet->setCellValue('A' . $currentRow, 'Event Attendance');
    $sheet->mergeCells('A' . $currentRow . ':G' . $currentRow);
    $sheet->getStyle('A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0c4a6e']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension($currentRow)->setRowHeight(28);
    $currentRow++;
    // Event name
    $sheet->setCellValue('A' . $currentRow, 'Event:');
    $sheet->setCellValue('B' . $currentRow, $eventTitle);
    $sheet->mergeCells('B' . $currentRow . ':G' . $currentRow);
    $sheet->getStyle('A' . $currentRow . ':A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0f9ff']]
    ]);
    $sheet->getStyle('B' . $currentRow . ':G' . $currentRow)->applyFromArray([
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0f9ff']]
    ]);
    $currentRow++;
    // Export date
    $sheet->setCellValue('A' . $currentRow, 'Exported:');
    $sheet->setCellValue('B' . $currentRow, date('F j, Y \a\t g:i A'));
    $sheet->mergeCells('B' . $currentRow . ':G' . $currentRow);
    $sheet->getStyle('A' . $currentRow . ':A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0f9ff']]
    ]);
    $sheet->getStyle('B' . $currentRow . ':G' . $currentRow)->applyFromArray([
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f0f9ff']]
    ]);
    $currentRow += 2;
    // Table header
    $headerRow = $currentRow;
    $headers = ['#', 'Safe Employee ID', 'Name', 'IN (Morning)', 'OUT (12:00 PM)', 'IN (1:00 PM)', 'OUT (Afternoon)'];
    foreach ($headers as $col => $text) {
        $sheet->setCellValueByColumnAndRow($col + 1, $currentRow, $text);
    }
    $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0e7490']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '0c4a6e']]]
    ]);
    $sheet->getRowDimension($currentRow)->setRowHeight(26);
    $currentRow++;
    $dataStartRow = $currentRow;
    $num = 1;
    foreach ($attendance as $row) {
        $sheet->setCellValue('A' . $currentRow, $num++);
        $sheet->setCellValue('B' . $currentRow, $row['employee_id'] ?? '');
        $sheet->setCellValue('C' . $currentRow, $row['name'] ?? '');
        $sheet->setCellValue('D' . $currentRow, $row['in_morning'] ? date('g:i A', strtotime($row['in_morning'])) : '—');
        $sheet->setCellValue('E' . $currentRow, $row['out_noon'] ? date('g:i A', strtotime($row['out_noon'])) : '—');
        $sheet->setCellValue('F' . $currentRow, $row['in_afternoon'] ? date('g:i A', strtotime($row['in_afternoon'])) : '—');
        $sheet->setCellValue('G' . $currentRow, $row['out_afternoon'] ? date('g:i A', strtotime($row['out_afternoon'])) : '—');
        $fillColor = ($currentRow % 2 === 0) ? 'f8fafc' : 'ffffff';
        $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'e2e8f0']]],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D' . $currentRow . ':G' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $currentRow++;
    }
    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(14);
    $sheet->getColumnDimension('C')->setWidth(28);
    $sheet->getColumnDimension('D')->setWidth(14);
    $sheet->getColumnDimension('E')->setWidth(16);
    $sheet->getColumnDimension('F')->setWidth(14);
    $sheet->getColumnDimension('G')->setWidth(16);
    $sheet->freezePane('A' . ($headerRow + 1));
    $filename = 'attendance-' . preg_replace('/[^a-z0-9]/i', '-', $eventTitle) . '-' . date('Y-m-d');
    $filename = trim(preg_replace('/-+/', '-', $filename), '-') ?: 'attendance-' . date('Y-m-d');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// List events – split into upcoming (event_date >= today) and past (event history)
$upcomingEvents = [];
$pastEvents = [];
try {
    $stmt = $db->query("
        SELECT e.id, e.title, e.description, e.event_date, e.event_time, e.end_time, e.location, e.qr_token, e.is_active, e.created_at,
               (SELECT COUNT(DISTINCT a.user_id) FROM hr_event_attendances a WHERE a.event_id = e.id) AS attendance_count
        FROM hr_events e
        ORDER BY e.event_date DESC, e.event_time DESC
    ");
    $allEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');
    foreach ($allEvents as $ev) {
        if ($ev['event_date'] >= $today) {
            $upcomingEvents[] = $ev;
        } else {
            $pastEvents[] = $ev;
        }
    }
} catch (Exception $e) {
    $listError = $e->getMessage();
}

$pageTitle = 'HR Events';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once __DIR__ . '/../../includes/admin_layout_helper.php';
    admin_page_head($pageTitle, 'Create events and manage event scanner for employee check-in');
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="<?php echo htmlspecialchars(rtrim($basePath, '/') . '/admin/css/events.css'); ?>?v=3" rel="stylesheet">
</head>
<body class="layout-admin hr-event-admin">
    <?php require_once __DIR__ . '/../../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <header class="hr-event-page-header">
                    <h1 class="hr-event-page-title"><i class="bi bi-calendar-event me-2" aria-hidden="true"></i>HR Events</h1>
                    <p class="hr-event-page-desc">Create events and manage check-in at the venue. Use the scanner link or QR code on a tablet or phone to scan each employee's SAFE QR code.</p>
                </header>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <section class="card hr-event-card hr-event-create-card mb-4" aria-labelledby="create-event-heading">
                    <div class="card-header" id="create-event-heading">
                        <i class="fas fa-plus-circle me-2" aria-hidden="true"></i>Create new event
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?php echo htmlspecialchars($basePath . '/admin/events.php'); ?>" class="hr-event-create-form">
                            <input type="hidden" name="action" value="create">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="event-title" class="form-label">Title <span class="text-danger" aria-hidden="true">*</span></label>
                                    <input type="text" id="event-title" name="title" class="form-control" required placeholder="e.g. Annual General Assembly" aria-required="true">
                                    <span class="form-text">Short name shown on scanner and reports.</span>
                                </div>
                                <div class="col-md-6">
                                    <label for="event-date" class="form-label">Event date <span class="text-danger" aria-hidden="true">*</span></label>
                                    <input type="date" id="event-date" name="event_date" class="form-control" required value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" aria-required="true">
                                </div>
                                <div class="col-12">
                                    <label for="event-desc" class="form-label">Description</label>
                                    <textarea id="event-desc" name="description" class="form-control" rows="2" placeholder="Optional notes for this event"></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label for="event-time" class="form-label">Start time</label>
                                    <input type="time" id="event-time" name="event_time" class="form-control" aria-label="Event start time">
                                </div>
                                <div class="col-md-4">
                                    <label for="event-end-time" class="form-label">End time</label>
                                    <input type="time" id="event-end-time" name="end_time" class="form-control" aria-label="Event end time">
                                </div>
                                <div class="col-md-4">
                                    <label for="event-location" class="form-label">Location</label>
                                    <input type="text" id="event-location" name="location" class="form-control" placeholder="e.g. Main Hall" aria-label="Event location">
                                </div>
                                <div class="col-12 hr-event-form-actions">
                                    <button type="submit" class="btn btn-primary btn-create-event"><i class="fas fa-plus me-1" aria-hidden="true"></i>Create event</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

                <?php if (isset($listError)): ?>
                    <div class="alert alert-warning">
                        <strong>Database setup required.</strong> The HR Events tables may not exist yet. Run the migration in your database:
                        <pre class="mt-2 mb-0 p-2 bg-light rounded"><code>db/migrations/20260217_create_hr_events.sql</code></pre>
                        <p class="mb-0 mt-2 small">Error: <?php echo htmlspecialchars($listError ?? ''); ?></p>
                    </div>
                <?php elseif (empty($upcomingEvents) && empty($pastEvents)): ?>
                    <section class="card hr-event-empty-card" aria-label="No events">
                        <div class="card-body hr-event-empty-body">
                            <i class="bi bi-calendar-x hr-event-empty-icon" aria-hidden="true"></i>
                            <h3 class="hr-event-empty-title">No events yet</h3>
                            <p class="hr-event-empty-text">Create an event above, then use the scanner link or QR code at the venue to check in employees with their SAFE QR code.</p>
                        </div>
                    </section>
                <?php else: ?>
                    <?php if (!empty($pastEvents)): ?>
                    <div class="hr-event-history-bar">
                        <button type="button" class="btn btn-outline-secondary btn-event-history" id="btnEventHistory" data-bs-toggle="modal" data-bs-target="#eventHistoryModal" aria-label="View past events">
                            <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Event history (<?php echo count($pastEvents); ?>)
                        </button>
                    </div>
                    <?php endif; ?>
                    <section class="card hr-event-list-card" aria-labelledby="events-list-heading">
                        <div class="card-header" id="events-list-heading">
                            <i class="bi bi-calendar-check me-2" aria-hidden="true"></i>Upcoming events
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if (empty($upcomingEvents)): ?>
                            <div class="list-group-item hr-event-no-upcoming">
                                <i class="bi bi-info-circle me-2" aria-hidden="true"></i>No upcoming events. View past events in <strong>Event history</strong> above.
                            </div>
                            <?php endif; ?>
                            <?php foreach ($upcomingEvents as $ev):
                                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                $host = $_SERVER['HTTP_HOST'] ?? '';
                                $scannerUrl = $proto . '://' . $host . rtrim($basePath, '/') . '/scanner.php?e=' . $ev['id'] . '&t=' . urlencode($ev['qr_token']);
                            ?>
                            <div class="list-group-item hr-event-item">
                                <div class="d-flex flex-wrap align-items-start gap-3">
                                    <div class="flex-grow-1">
                                        <h6 class="hr-event-title mb-1">
                                            <?php echo htmlspecialchars($ev['title']); ?>
                                            <?php if (!$ev['is_active']): ?><span class="badge bg-secondary ms-2">Inactive</span><?php endif; ?>
                                        </h6>
                                        <p class="hr-event-meta mb-0">
                                            <i class="bi bi-calendar3 me-1"></i><?php echo date('M j, Y', strtotime($ev['event_date'])); ?>
                                            <?php if ($ev['event_time']): ?> &bull; <?php echo date('g:i A', strtotime($ev['event_time'])); endif; ?>
                                            <?php if (!empty($ev['location'])): ?> &bull; <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($ev['location']); endif; ?>
                                        </p>
                                        <p class="hr-event-count mb-0 mt-1">
                                            <i class="bi bi-people me-1"></i><?php echo (int)$ev['attendance_count']; ?> employee(s)
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-view-attendance" data-event-id="<?php echo (int)$ev['id']; ?>" data-event-title="<?php echo htmlspecialchars($ev['title']); ?>"><i class="bi bi-list-ul me-1"></i>View attendance</button>
                                        </p>
                                    </div>
                                    <div class="hr-event-qr-block">
                                        <img src="qr_image.php?event_id=<?php echo (int)$ev['id']; ?>" alt="Open scanner" width="120" height="120" title="Scan this on the venue device to open the event scanner">
                                        <p class="hr-event-qr-caption mx-auto">Scan on device to open scanner</p>
                                        <a href="qr_image.php?event_id=<?php echo (int)$ev['id']; ?>" download="event-<?php echo (int)$ev['id']; ?>-scanner-qr.png" class="btn btn-sm btn-outline-primary btn-download-qr">Download QR</a>
                                    </div>
                                </div>
                                <div class="hr-event-scanner-link-wrap">
                                    <label class="form-label d-block">Event scanner link</label>
                                    <p class="hr-event-scanner-hint">Open this link on a tablet or phone at the venue, then scan each employee's SAFE QR code to check them in.</p>
                                    <div class="hr-event-scanner-link-row">
                                        <input type="text" class="form-control font-monospace hr-event-scanner-input" readonly
                                               value="<?php echo htmlspecialchars($scannerUrl); ?>"
                                               id="scanner-url-<?php echo (int)$ev['id']; ?>"
                                               onclick="this.select();" aria-label="Scanner URL">
                                        <div class="hr-event-scanner-btns">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-copy-scanner-link" data-url="<?php echo htmlspecialchars($scannerUrl); ?>" title="Copy link" aria-label="Copy scanner link">
                                                <i class="bi bi-clipboard me-1"></i><span class="btn-copy-text">Copy link</span>
                                            </button>
                                            <a href="<?php echo htmlspecialchars($scannerUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary btn-open-scanner"><i class="bi bi-box-arrow-up-right me-1"></i>Open scanner</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Event History modal -->
    <div class="modal fade" id="eventHistoryModal" tabindex="-1" aria-labelledby="eventHistoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventHistoryModalLabel"><i class="bi bi-clock-history me-2"></i>Event History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Past events. Scanning is no longer available for these events.</p>
                    <div class="list-group list-group-flush">
                        <?php foreach ($pastEvents as $ev): ?>
                        <div class="list-group-item">
                            <div class="d-flex flex-wrap align-items-start gap-3">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($ev['title']); ?>
                                        <?php if (!$ev['is_active']): ?><span class="badge bg-secondary ms-2">Inactive</span><?php endif; ?>
                                    </h6>
                                    <p class="text-muted small mb-1">
                                        <i class="bi bi-calendar3 me-1"></i><?php echo date('M j, Y', strtotime($ev['event_date'])); ?>
                                        <?php if ($ev['event_time']): ?> &bull; <?php echo date('g:i A', strtotime($ev['event_time'])); endif; ?>
                                        <?php if (!empty($ev['location'])): ?> &bull; <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($ev['location']); endif; ?>
                                    </p>
                                    <p class="mb-0">
                                        <i class="bi bi-people me-1"></i><?php echo (int)$ev['attendance_count']; ?> employee(s)
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-view-attendance" data-event-id="<?php echo (int)$ev['id']; ?>" data-event-title="<?php echo htmlspecialchars($ev['title']); ?>"><i class="bi bi-list-ul me-1"></i>View attendance</button>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance modal -->
    <div class="modal fade" id="attendanceModal" tabindex="-1" aria-labelledby="attendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content hr-event-attendance-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="attendanceModalLabel"><i class="bi bi-people me-2" aria-hidden="true"></i>Attendance <span id="attendanceModalCount" class="badge bg-primary ms-2"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="attendanceModalEventTitle"></p>
                    <div id="attendanceModalLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                        <p class="mt-2 mb-0 text-muted">Loading attendance...</p>
                    </div>
                    <div id="attendanceModalContent" class="d-none">
                        <div class="hr-event-export-bar" id="hrEventExportBar">
                            <div class="hr-event-export-info">
                                <i class="bi bi-file-earmark-spreadsheet hr-event-export-icon" aria-hidden="true"></i>
                                <div>
                                    <span class="hr-event-export-title">Export attendance</span>
                                    <p class="hr-event-export-desc">Download as Excel (.xlsx) or CSV if the server cannot generate Excel.</p>
                                </div>
                            </div>
                            <button type="button" class="btn btn-success hr-event-export-btn" id="btnExportAttendanceExcel" aria-label="Export attendance as Excel">
                                <i class="bi bi-download me-2" aria-hidden="true"></i><span class="hr-event-export-btn-text">Export Excel</span>
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle" id="attendanceModalTable" aria-describedby="attendanceModalEventTitle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Safe Employee ID</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">IN (Morning)</th>
                                        <th scope="col">OUT (12:00 PM)</th>
                                        <th scope="col">IN (1:00 PM)</th>
                                        <th scope="col">OUT (Afternoon)</th>
                                    </tr>
                                </thead>
                                <tbody id="attendanceModalTableBody">
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mb-0" id="attendanceModalEmpty" style="display:none;"><i class="bi bi-inbox me-1"></i>No check-ins recorded yet.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script>
    (function() {
        const modal = document.getElementById('attendanceModal');
        const loadingEl = document.getElementById('attendanceModalLoading');
        const contentEl = document.getElementById('attendanceModalContent');
        const tableBody = document.getElementById('attendanceModalTableBody');
        const emptyEl = document.getElementById('attendanceModalEmpty');
        const titleEl = document.getElementById('attendanceModalEventTitle');
        const countEl = document.getElementById('attendanceModalCount');
        let currentAttendanceData = [];
        let currentEventTitle = '';
        let currentEventId = '';

        document.querySelectorAll('.btn-view-attendance').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const eventId = this.getAttribute('data-event-id');
                currentEventId = eventId;
                currentEventTitle = this.getAttribute('data-event-title') || 'Event';
                var historyModalEl = document.getElementById('eventHistoryModal');
                if (historyModalEl && bootstrap.Modal.getInstance(historyModalEl)) {
                    bootstrap.Modal.getInstance(historyModalEl).hide();
                }
                var labelEl = document.getElementById('attendanceModalLabel');
                labelEl.innerHTML = '<i class="bi bi-people me-2" aria-hidden="true"></i>Attendance <span id="attendanceModalCount" class="badge bg-primary ms-2"></span>';
                titleEl.textContent = currentEventTitle;
                countEl.textContent = '';
                var exportBar = document.getElementById('hrEventExportBar');
                if (exportBar) exportBar.style.display = 'none';
                loadingEl.classList.remove('d-none');
                contentEl.classList.add('d-none');
                emptyEl.style.display = 'none';
                tableBody.innerHTML = '';
                currentAttendanceData = [];

                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();

                fetch('events.php?action=get_attendance&event_id=' + encodeURIComponent(eventId))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        loadingEl.classList.add('d-none');
                        contentEl.classList.remove('d-none');
                        var countBadge = document.getElementById('attendanceModalCount');
                        if (data.success && data.attendance && data.attendance.length > 0) {
                            currentAttendanceData = data.attendance;
                            if (countBadge) countBadge.textContent = data.attendance.length;
                            var exportBar = document.getElementById('hrEventExportBar');
                            if (exportBar) exportBar.style.display = '';
                            emptyEl.style.display = 'none';
                            data.attendance.forEach(function(row, i) {
                                const tr = document.createElement('tr');
                                tr.innerHTML = '<td>' + (i + 1) + '</td>' +
                                    '<td>' + (row.employee_id ? escapeHtml(row.employee_id) : '—') + '</td>' +
                                    '<td>' + escapeHtml(row.name || '—') + '</td>' +
                                    '<td>' + formatTime(row.in_morning) + '</td>' +
                                    '<td>' + formatTime(row.out_noon) + '</td>' +
                                    '<td>' + formatTime(row.in_afternoon) + '</td>' +
                                    '<td>' + formatTime(row.out_afternoon) + '</td>';
                                tableBody.appendChild(tr);
                            });
                        } else {
                            if (countBadge) countBadge.textContent = '0';
                            emptyEl.style.display = 'block';
                        }
                    })
                    .catch(function(err) {
                        loadingEl.classList.add('d-none');
                        contentEl.classList.remove('d-none');
                        emptyEl.textContent = 'Could not load attendance. Please try again.';
                        emptyEl.style.display = 'block';
                    });
            });
        });

        document.getElementById('btnExportAttendanceExcel').addEventListener('click', function() {
            if (!currentEventId || currentAttendanceData.length === 0) return;
            var btn = this;
            var textEl = btn.querySelector('.hr-event-export-btn-text');
            var iconEl = btn.querySelector('.bi-download');
            var exportUrl = 'events.php?action=export_attendance_xlsx&event_id=' + encodeURIComponent(currentEventId);
            btn.disabled = true;
            if (textEl) textEl.textContent = 'Preparing…';
            fetch(exportUrl, { credentials: 'same-origin' })
                .then(function(res) {
                    var ct = (res.headers.get('content-type') || '').toLowerCase();
                    var isFile = ct.indexOf('spreadsheet') !== -1 || ct.indexOf('octet-stream') !== -1 || ct.indexOf('text/csv') !== -1;
                    if (res.ok && isFile) return res.blob().then(function(blob) { return { blob: blob, isCsv: ct.indexOf('text/csv') !== -1 }; });
                    return res.text().then(function(text) {
                        var msg = 'Export failed.';
                        try {
                            var j = JSON.parse(text);
                            if (j && j.error) msg = j.error;
                        } catch (e) { if (text && text.length < 200) msg = text; }
                        throw new Error(msg);
                    });
                })
                .then(function(result) {
                    var blob = result.blob;
                    var ext = result.isCsv ? '.csv' : '.xlsx';
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'attendance-' + (currentEventTitle.replace(/[^a-z0-9]/gi, '-') || 'event') + '-' + new Date().toISOString().slice(0, 10) + ext;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(a.href);
                    btn.classList.add('hr-event-export-btn-done');
                    if (textEl) textEl.textContent = 'Downloaded';
                    if (iconEl) { iconEl.classList.remove('bi-download'); iconEl.classList.add('bi-check-lg'); }
                })
                .catch(function(err) {
                    if (textEl) textEl.textContent = 'Export Excel';
                    alert(err.message || 'Download failed. Please try again.');
                })
                .finally(function() {
                    btn.disabled = false;
                    setTimeout(function() {
                        btn.classList.remove('hr-event-export-btn-done');
                        if (textEl) textEl.textContent = 'Export Excel';
                        if (iconEl) { iconEl.classList.remove('bi-check-lg'); iconEl.classList.add('bi-download'); }
                    }, 2000);
                });
        });

        document.querySelectorAll('.btn-copy-scanner-link').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var url = this.getAttribute('data-url');
                var span = this.querySelector('.btn-copy-text');
                if (!url) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function() {
                        if (span) { span.textContent = 'Copied!'; }
                        setTimeout(function() { if (span) span.textContent = 'Copy link'; }, 2000);
                    }).catch(function() { fallbackCopy(url, span); });
                } else {
                    fallbackCopy(url, span);
                }
            });
        });
        function fallbackCopy(url, span) {
            var input = document.createElement('input');
            input.value = url;
            input.setAttribute('readonly', '');
            input.style.position = 'absolute';
            input.style.left = '-9999px';
            document.body.appendChild(input);
            input.select();
            try {
                document.execCommand('copy');
                if (span) { span.textContent = 'Copied!'; }
                setTimeout(function() { if (span) span.textContent = 'Copy link'; }, 2000);
            } catch (e) {}
            document.body.removeChild(input);
        }

        function escapeHtml(s) {
            if (s == null) return '';
            var div = document.createElement('div');
            div.textContent = String(s);
            return div.innerHTML;
        }
        function formatTime(s) {
            if (!s) return '—';
            var d = new Date(s);
            return isNaN(d.getTime()) ? s : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    })();
    </script>
</body>
</html>
