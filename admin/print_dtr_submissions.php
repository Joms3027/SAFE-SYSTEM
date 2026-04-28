<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
if (!$tableCheck || $tableCheck->rowCount() === 0) {
    $_SESSION['error'] = 'DTR submissions are not configured. Please run the database migration.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/admin/dtr_submissions.php', $basePath));
}

$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$departmentFilter = isset($_GET['department']) ? trim($_GET['department']) : '';
$employeeTypeFilter = isset($_GET['employee_type']) ? trim($_GET['employee_type']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$dateFrom = sprintf('%04d-%02d-01', $yearFilter, $monthFilter);
$daysInMonth = (int)date('t', strtotime($dateFrom));
$dateTo = sprintf('%04d-%02d-%02d', $yearFilter, $monthFilter, $daysInMonth);

$whereClause = "ds.log_date >= ? AND ds.log_date <= ?";
$params = [$dateFrom, $dateTo];

// Admin only prints submissions that have been verified (dean or pardon opener)
// Staff must be in a pardon opener's scope to match admin/dtr_submissions listing
$colCheck = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'");
$hasPardonOpenerTable = false;
$tblCheck = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
if ($tblCheck && $tblCheck->rowCount() > 0) {
    $hasPardonOpenerTable = true;
}
if ($colCheck && $colCheck->rowCount() > 0) {
    if ($hasPardonOpenerTable) {
        $whereClause .= " AND ds.dean_verified_at IS NOT NULL AND (
            u.user_type = 'faculty'
            OR (u.user_type = 'staff' AND EXISTS (
                SELECT 1 FROM pardon_opener_assignments poa
                WHERE (
                    (poa.scope_type = 'department' AND TRIM(COALESCE(fp.department, '')) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(TRIM(fp.department)))
                    OR (poa.scope_type = 'designation' AND TRIM(poa.scope_value) != '' AND TRIM(COALESCE(fp.designation, '')) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(TRIM(fp.designation)))
                )
            ))
        )";
    } else {
        $whereClause .= " AND ds.dean_verified_at IS NOT NULL";
    }
}

if ($departmentFilter !== '') {
    $whereClause .= " AND COALESCE(fp.department, '') = ?";
    $params[] = $departmentFilter;
}

if ($employeeTypeFilter === 'faculty' || $employeeTypeFilter === 'staff') {
    $whereClause .= " AND u.user_type = ?";
    $params[] = $employeeTypeFilter;
}

if ($search !== '') {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR fp.employee_id LIKE ? OR COALESCE(fp.department, '') LIKE ?)";
    $term = '%' . $search . '%';
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$sql = "SELECT ds.id, ds.log_date, u.first_name, u.last_name, fp.employee_id, fp.department
    FROM dtr_daily_submissions ds
    INNER JOIN users u ON ds.user_id = u.id
    LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
    WHERE $whereClause
    ORDER BY ds.log_date ASC, u.last_name ASC, u.first_name ASC
    LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fmt = function($t) { return $t ? substr($t, 0, 5) : ''; };
$parseTimeToMinutes = function($timeStr) {
    if (!$timeStr || trim($timeStr) === '') return null;
    $parts = explode(':', trim($timeStr));
    if (count($parts) >= 2) return (int)$parts[0] * 60 + (int)$parts[1];
    return null;
};
$parseOfficialTimes = function($str) use ($parseTimeToMinutes) {
    $lunchOut = 12 * 60;
    $lunchIn = 13 * 60;
    $timeOut = 17 * 60;
    if (!$str || $str === '—' || $str === '-') return ['lunch_out' => $lunchOut, 'lunch_in' => $lunchIn, 'time_out' => $timeOut];
    $parts = explode(',', $str);
    if (count($parts) >= 2) {
        $am = explode('-', trim($parts[0]));
        $pm = explode('-', trim($parts[1]));
        if (count($am) >= 2) {
            $lo = $parseTimeToMinutes(trim($am[1]));
            if ($lo !== null) $lunchOut = $lo;
        }
        if (count($pm) >= 2) {
            $li = $parseTimeToMinutes(trim($pm[0]));
            if ($li !== null) $lunchIn = $li;
            $to = $parseTimeToMinutes(trim($pm[1]));
            if ($to !== null) $timeOut = $to;
        }
    } elseif (count($parts) === 1) {
        $seg = explode('-', trim($parts[0]));
        if (count($seg) >= 2) {
            $end = $parseTimeToMinutes(trim($seg[1]));
            if ($end !== null) { $lunchOut = $end; $lunchIn = $end; $timeOut = $end; }
        }
    }
    return ['lunch_out' => $lunchOut, 'lunch_in' => $lunchIn, 'time_out' => $timeOut];
};

$dtrs = [];
foreach ($submissions as $row) {
    $employeeId = $row['employee_id'] ?? '';
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($name === '') $name = '—';
    $logDate = $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : '';

    $log = null;
    $query = "SELECT al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.remarks, al.tarf_id
        FROM attendance_logs al
        WHERE al.employee_id = ? AND al.log_date = ?
        LIMIT 1";
    $stmtLog = $db->prepare($query);
    $stmtLog->execute([$employeeId, $logDate]);
    $r = $stmtLog->fetch(PDO::FETCH_ASSOC);
    $isTarfLog = false;
    if ($r) {
        $remarksRaw = (string) ($r['remarks'] ?? '');
        $isTarfLog = (
            (!empty($r['tarf_id']) && strpos($remarksRaw, 'TARF:') === 0)
            || strpos($remarksRaw, 'TARF_HOURS_CREDIT:') !== false
            || strtoupper(trim($remarksRaw)) === 'TARF'
        );
        if ($isTarfLog) {
            $log = [
                'time_in' => 'TRAVEL',
                'lunch_out' => 'TRAVEL',
                'lunch_in' => 'TRAVEL',
                'time_out' => 'TRAVEL',
            ];
        } else {
            $log = [
                'time_in' => $r['time_in'] ? $fmt($r['time_in']) : '',
                'lunch_out' => $r['lunch_out'] ? $fmt($r['lunch_out']) : '',
                'lunch_in' => $r['lunch_in'] ? $fmt($r['lunch_in']) : '',
                'time_out' => $r['time_out'] ? $fmt($r['time_out']) : '',
            ];
        }
    } else {
        $log = ['time_in' => '', 'lunch_out' => '', 'lunch_in' => '', 'time_out' => ''];
    }

    $official_regular = '08:00-12:00, 13:00-17:00';
    $official_saturday = '—';
    $stmtOT = $db->prepare("SELECT weekday, time_in, lunch_out, lunch_in, time_out FROM employee_official_times WHERE employee_id = ? AND (weekday = 'Monday' OR weekday = 'Saturday') ORDER BY start_date DESC");
    $stmtOT->execute([$employeeId]);
    $official_regular_set = false;
    $official_saturday_set = false;
    while ($ot = $stmtOT->fetch(PDO::FETCH_ASSOC)) {
        $w = trim($ot['weekday'] ?? '');
        $seg = ($fmt($ot['time_in']) && $fmt($ot['lunch_out']) ? $fmt($ot['time_in']) . '-' . $fmt($ot['lunch_out']) : '') . ($fmt($ot['lunch_in']) && $fmt($ot['time_out']) ? ', ' . $fmt($ot['lunch_in']) . '-' . $fmt($ot['time_out']) : '');
        if ($w === 'Monday' && $seg && !$official_regular_set) { $official_regular = $seg; $official_regular_set = true; }
        if ($w === 'Saturday' && $seg && !$official_saturday_set) { $official_saturday = $seg; $official_saturday_set = true; }
    }

    $in_charge = '—';
    $inChargeVal = function_exists('getPardonOpenerDisplayNameForEmployee') ? getPardonOpenerDisplayNameForEmployee($employeeId, $db) : '';
    if ($inChargeVal !== '' && $inChargeVal !== 'HR') {
        $in_charge = $inChargeVal;
    } else {
        $empDepartment = trim($row['department'] ?? '');
        if ($empDepartment !== '') {
            $stmtDean = $db->prepare("SELECT u.first_name, u.last_name, fp.department FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.department = ? AND LOWER(TRIM(COALESCE(fp.designation, ''))) = 'dean' LIMIT 1");
            $stmtDean->execute([$empDepartment]);
            $dean = $stmtDean->fetch(PDO::FETCH_ASSOC);
            if ($dean) {
                $in_charge = trim(($dean['first_name'] ?? '') . ' ' . ($dean['last_name'] ?? ''));
                if (trim($dean['department'] ?? '') !== '') $in_charge .= ', ' . trim($dean['department']);
            }
        }
    }

    $dayNum = $logDate ? (int)date('j', strtotime($logDate)) : 0;
    $dateLabel = $logDate ? date('F j, Y', strtotime($logDate)) : '—';

    $dtrs[] = [
        'name' => $name,
        'log_date' => $logDate,
        'date_label' => $dateLabel,
        'day' => $dayNum,
        'official_regular' => $official_regular,
        'official_saturday' => $official_saturday,
        'in_charge' => $in_charge,
        'log' => $log,
    ];
}

$monthLabel = date('F Y', strtotime($dateFrom));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Print - <?php echo htmlspecialchars($monthLabel); ?></title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: "Times New Roman", Times, serif; color: #000; background: #fff; }
        .no-print { padding: 12px; text-align: right; background: #f0f0f0; border-bottom: 1px solid #ccc; }
        .no-print button { padding: 8px 16px; margin-left: 8px; cursor: pointer; background: #007bff; color: #fff; border: none; border-radius: 4px; }
        .no-print button:hover { background: #0056b3; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
        }

        .a4-page {
            width: 210mm;
            min-height: 297mm;
            padding: 0;
            margin: 0 auto 0 auto;
            page-break-after: always;
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            justify-content: space-between;
            align-items: stretch;
            gap: 12mm;
        }
        .a4-page:last-child { page-break-after: auto; }

        .dtr-form-wrap {
            flex: 1 1 0;
            min-width: 0;
            font-family: "Times New Roman", Times, serif; color: #000; background: #fff;
            padding: 6px 8px;
            border: 1px solid #000;
            font-size: 8px;
        }

        .dtr-form-title { font-size: 9px; font-weight: 700; text-align: center; margin-bottom: 1px; }
        .dtr-form-subtitle { font-size: 9px; font-weight: 700; text-align: center; margin-bottom: 1px; }
        .dtr-form-line { text-align: center; font-size: 7px; letter-spacing: 0.12em; margin-bottom: 3px; }
        .dtr-field-row { font-size: 8px; margin-bottom: 1px; }
        .dtr-field-inline { display: inline-block; border-bottom: 1px solid #000; min-width: 90px; margin-left: 2px; padding: 0 2px; }
        .dtr-official-label { font-size: 7px; margin-bottom: 1px; }
        .dtr-official-row { font-size: 7px; margin-bottom: 1px; }
        .dtr-table { font-size: 6px; table-layout: fixed; width: 100%; border-collapse: collapse; }
        .dtr-table th, .dtr-table td { padding: 1px 2px; vertical-align: middle; border: 1px solid #000; }
        .dtr-table th { background: #fff; font-weight: 700; text-align: center; }
        .dtr-table .dtr-day { width: 1.8em; text-align: center; }
        .dtr-table .dtr-time { width: 2.8em; text-align: center; }
        .dtr-table .dtr-undertime { width: 2em; text-align: center; }
        .dtr-table tbody tr.dtr-total { font-weight: 700; }
        .dtr-certify { font-size: 6px; margin-top: 3px; margin-bottom: 1px; line-height: 1.2; }
        .dtr-verified { font-size: 6px; margin-top: 3px; margin-bottom: 0; }
        .dtr-verified .dtr-incharge { display: block; font-weight: 700; margin-top: 1px; }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" onclick="window.close()">Close</button>
        <span style="margin-left: 12px; color: #666;"><?php echo count($dtrs); ?> daily DTR(s) — <?php echo htmlspecialchars($monthLabel); ?> — 2 per A4 page</span>
    </div>

    <?php
    $chunks = array_chunk($dtrs, 2);
    foreach ($chunks as $pair):
        $pair = array_pad($pair, 2, null);
    ?>
    <div class="a4-page">
        <?php foreach ($pair as $dtr):
            if (!$dtr) continue;
            $log = $dtr['log'];
            $timeIn = $log['time_in'] ?? '';
            $lunchOut = $log['lunch_out'] ?? '';
            $lunchIn = $log['lunch_in'] ?? '';
            $timeOut = $log['time_out'] ?? '';
            $utHrs = ''; $utMin = '';
            $isTarfDay = ($timeIn === 'TRAVEL' || $lunchOut === 'TRAVEL' || $lunchIn === 'TRAVEL' || $timeOut === 'TRAVEL');
            $isSaturday = $dtr['log_date'] ? (int)date('w', strtotime($dtr['log_date'])) === 6 : false;
            $official = $parseOfficialTimes($isSaturday ? $dtr['official_saturday'] : $dtr['official_regular']);
            $undertimeMinutes = 0;
            if (!$isTarfDay) {
                if ($lunchOut !== '' && trim($lunchOut) !== '' && $lunchOut !== '00:00' && $lunchOut !== '0:00') {
                    $actualLunchOut = $parseTimeToMinutes($lunchOut);
                    if ($actualLunchOut !== null && $actualLunchOut < $official['lunch_out']) {
                        $undertimeMinutes += $official['lunch_out'] - $actualLunchOut;
                    }
                }
                if ($timeOut !== '' && trim($timeOut) !== '' && $timeOut !== '00:00' && $timeOut !== '0:00') {
                    $actualOut = $parseTimeToMinutes($timeOut);
                    if ($actualOut !== null && $actualOut < $official['time_out']) {
                        $undertimeMinutes += $official['time_out'] - $actualOut;
                    }
                } elseif ($lunchIn !== '' && trim($lunchIn) !== '' && $lunchIn !== '00:00' && $lunchIn !== '0:00') {
                    $undertimeMinutes += $official['time_out'] - $official['lunch_in'];
                }
            }
            if ($undertimeMinutes > 0) {
                $utHrs = (string)(int)floor($undertimeMinutes / 60);
                $utMin = (string)($undertimeMinutes % 60);
            }
        ?>
        <div class="dtr-form-wrap">
            <div class="dtr-form-title">Civil Service Form No. 48</div>
            <div class="dtr-form-subtitle">DAILY TIME RECORD</div>
            <div class="dtr-form-line">-----o0o-----</div>
            <div class="dtr-field-row">(Name) <span class="dtr-field-inline"><?php echo htmlspecialchars($dtr['name']); ?></span></div>
            <div class="dtr-field-row">Date: <span class="dtr-field-inline"><?php echo htmlspecialchars($dtr['date_label']); ?></span></div>
            <div class="dtr-official-label">Official hours for</div>
            <div class="dtr-official-label">arrival and departure</div>
            <div class="dtr-official-row">Regular days <span class="dtr-field-inline"><?php echo htmlspecialchars($dtr['official_regular']); ?></span></div>
            <div class="dtr-official-row">Saturdays <span class="dtr-field-inline"><?php echo htmlspecialchars($dtr['official_saturday']); ?></span></div>
            <table class="dtr-table">
                <thead>
                    <tr>
                        <th class="dtr-day">Day</th>
                        <th colspan="2">A.M.</th>
                        <th colspan="2">P.M.</th>
                        <th colspan="2">Undertime</th>
                    </tr>
                    <tr>
                        <th></th>
                        <th class="dtr-time">Arrival</th>
                        <th class="dtr-time">Departure</th>
                        <th class="dtr-time">Arrival</th>
                        <th class="dtr-time">Departure</th>
                        <th class="dtr-undertime">Hours</th>
                        <th class="dtr-undertime">Minutes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="dtr-day"><?php echo (int)$dtr['day']; ?></td>
                        <td class="dtr-time"><?php echo htmlspecialchars($timeIn); ?></td>
                        <td class="dtr-time"><?php echo htmlspecialchars($lunchOut); ?></td>
                        <td class="dtr-time"><?php echo htmlspecialchars($lunchIn); ?></td>
                        <td class="dtr-time"><?php echo htmlspecialchars($timeOut); ?></td>
                        <td class="dtr-undertime"><?php echo htmlspecialchars($utHrs); ?></td>
                        <td class="dtr-undertime"><?php echo htmlspecialchars($utMin); ?></td>
                    </tr>
                    <tr class="dtr-total">
                        <td class="dtr-day">Total</td>
                        <td></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                </tbody>
            </table>
            <p class="dtr-certify">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>
            <p class="dtr-verified">VERIFIED as to the prescribed office hours:<br>In Charge<br><strong class="dtr-incharge"><?php echo htmlspecialchars($dtr['in_charge']); ?></strong></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if (empty($dtrs)): ?>
    <div class="a4-page">
        <p style="padding: 20px; text-align: center;">No DTR submissions found for the selected filters.</p>
    </div>
    <?php endif; ?>
</body>
</html>
