<?php
/**
 * Bulk print: Civil Service Form No. 48 monthly DTR for all employees (faculty & staff).
 * Two duplicate forms per A4 portrait page for each employee (Appendix 24 style). No pardon column.
 */
set_time_limit(300);

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/staff_dtr_month_data.php';
require_once '../includes/calendar_holiday_week_schedule.php';

requireAdmin();

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}

$dateFrom = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($dateFrom));
$dateTo = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
$monthLabel = date('F Y', strtotime($dateFrom));

$database = Database::getInstance();
$db = $database->getConnection();

$stmtEmp = $db->prepare("SELECT fp.employee_id, fp.position, fp.department,
    COALESCE(u.first_name, '') as first_name, COALESCE(u.last_name, '') as last_name
    FROM faculty_profiles fp
    LEFT JOIN users u ON fp.user_id = u.id
    WHERE u.user_type IN ('staff', 'faculty') AND u.is_active = 1
    ORDER BY u.last_name ASC, u.first_name ASC");
$stmtEmp->execute();
$employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

/**
 * @return array{lunchOut:int,lunchIn:int,timeOut:int}
 */
function print_emp_dtr_parse_official_minutes($str) {
    $lunchOutMin = 12 * 60;
    $lunchInMin = 13 * 60;
    $timeOutMin = 17 * 60;
    if (!$str || $str === '—' || $str === '-') {
        return ['lunchOut' => $lunchOutMin, 'lunchIn' => $lunchInMin, 'timeOut' => $timeOutMin];
    }
    $parts = explode(',', (string) $str);
    if (count($parts) >= 2) {
        $am = preg_split('/\s*-\s*/', trim($parts[0]));
        $pm = preg_split('/\s*-\s*/', trim($parts[1]));
        if (count($am) >= 2) {
            $lo = print_emp_dtr_parse_time_minutes(trim($am[1]));
            if ($lo !== null) {
                $lunchOutMin = $lo;
            }
        }
        if (count($pm) >= 2) {
            $li = print_emp_dtr_parse_time_minutes(trim($pm[0]));
            if ($li !== null) {
                $lunchInMin = $li;
            }
            $to = print_emp_dtr_parse_time_minutes(trim($pm[1]));
            if ($to !== null) {
                $timeOutMin = $to;
            }
        }
    } elseif (count($parts) === 1) {
        $seg = preg_split('/\s*-\s*/', trim($parts[0]));
        if (count($seg) >= 2) {
            $end = print_emp_dtr_parse_time_minutes(trim($seg[1]));
            if ($end !== null) {
                $lunchOutMin = $end;
                $lunchInMin = $end;
                $timeOutMin = $end;
            }
        }
    }
    return ['lunchOut' => $lunchOutMin, 'lunchIn' => $lunchInMin, 'timeOut' => $timeOutMin];
}

function print_emp_dtr_parse_time_minutes($timeStr) {
    if ($timeStr === null || $timeStr === '') {
        return null;
    }
    $p = explode(':', trim((string) $timeStr));
    if (count($p) >= 2) {
        return ((int) $p[0]) * 60 + ((int) $p[1]);
    }
    return null;
}

/**
 * @param array|null $log
 * @return array{h: string, m: string}
 */
function print_emp_dtr_undertime($log, $isSaturday, $officialRegular, $officialSaturday, $db = null) {
    if (!$log) {
        return ['h' => '—', 'm' => '—'];
    }
    $timeIn = $log['time_in'] ?? '';
    $lunchOut = $log['lunch_out'] ?? '';
    $lunchIn = $log['lunch_in'] ?? '';
    $timeOut = $log['time_out'] ?? '';
    $isLeave = ($timeIn === 'LEAVE' || $lunchOut === 'LEAVE' || $lunchIn === 'LEAVE' || $timeOut === 'LEAVE');
    $isHolidayRow = ($timeIn === 'HOLIDAY' || $lunchOut === 'HOLIDAY' || $lunchIn === 'HOLIDAY' || $timeOut === 'HOLIDAY');
    if ($isLeave || $isHolidayRow) {
        return ['h' => '—', 'm' => '—'];
    }
    $official = $isSaturday
        ? print_emp_dtr_parse_official_minutes($officialSaturday)
        : print_emp_dtr_parse_official_minutes($officialRegular);
    if ($db instanceof PDO && $log && !empty($log['log_date'])) {
        $ld = substr((string) $log['log_date'], 0, 10);
        if ($ld !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ld) && calendar_should_apply_holiday_week_eight_hours($db, $ld)) {
            $official = ['lunchOut' => 12 * 60, 'lunchIn' => 13 * 60, 'timeOut' => 17 * 60];
        }
    }
    $undertimeMinutes = 0;
    $hasLunchOut = $lunchOut && trim((string) $lunchOut) !== '' && $lunchOut !== '00:00' && $lunchOut !== '0:00';
    $hasLunchIn = $lunchIn && trim((string) $lunchIn) !== '' && $lunchIn !== '00:00' && $lunchIn !== '0:00';
    $hasTimeOut = $timeOut && trim((string) $timeOut) !== '' && $timeOut !== '00:00' && $timeOut !== '0:00';
    if ($hasLunchOut) {
        $actualLunchOut = print_emp_dtr_parse_time_minutes($lunchOut);
        if ($actualLunchOut !== null && $actualLunchOut < $official['lunchOut']) {
            $undertimeMinutes += $official['lunchOut'] - $actualLunchOut;
        }
    }
    if ($hasTimeOut) {
        $actualOut = print_emp_dtr_parse_time_minutes($timeOut);
        if ($actualOut !== null && $actualOut < $official['timeOut']) {
            $undertimeMinutes += $official['timeOut'] - $actualOut;
        }
    } elseif ($hasLunchIn) {
        $undertimeMinutes += $official['timeOut'] - $official['lunchIn'];
    }
    return [
        'h' => (string) (int) floor($undertimeMinutes / 60),
        'm' => (string) ($undertimeMinutes % 60),
    ];
}

$dtrForms = [];
foreach ($employees as $emp) {
    $name = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
    if ($name === '') {
        $name = 'Staff ' . ($emp['employee_id'] ?? '');
    }
    $bundle = staff_dtr_fetch_month_bundle($db, $emp['employee_id'], $dateFrom, $dateTo);
    $logByDate = [];
    foreach ($bundle['logs'] as $le) {
        if (!empty($le['log_date'])) {
            $logByDate[$le['log_date']] = $le;
        }
    }
    $rows = [];
    for ($day = 1; $day <= 31; $day++) {
        if (!checkdate($month, $day, $year)) {
            $rows[] = [
                'day' => $day,
                'valid' => false,
                'time_in' => '',
                'lunch_out' => '',
                'lunch_in' => '',
                'time_out' => '',
                'ut_h' => '',
                'ut_m' => '',
            ];
            continue;
        }
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $log = isset($logByDate[$dateKey]) ? $logByDate[$dateKey] : null;
        $ts = strtotime($dateKey);
        $isSaturday = $ts !== false && (int) date('w', $ts) === 6;
        $isTarfRow = $log && !empty($log['is_tarf']);
        $ut = $isTarfRow ? ['h' => '—', 'm' => '—'] : print_emp_dtr_undertime($log, $isSaturday, $bundle['official_regular'], $bundle['official_saturday'], $db);
        $mapHol = static function ($v) {
            return ($v === 'HOLIDAY') ? 'HOLIDAY' : (string) $v;
        };
        if ($isTarfRow) {
            $ti = $lo = $li = $to = 'TARF';
        } else {
            $ti = $log ? $mapHol($log['time_in'] ?? '') : '';
            $lo = $log ? $mapHol($log['lunch_out'] ?? '') : '';
            $li = $log ? $mapHol($log['lunch_in'] ?? '') : '';
            $to = $log ? $mapHol($log['time_out'] ?? '') : '';
        }
        $rows[] = [
            'day' => $day,
            'valid' => true,
            'time_in' => $ti,
            'lunch_out' => $lo,
            'lunch_in' => $li,
            'time_out' => $to,
            'ut_h' => $ut['h'],
            'ut_m' => $ut['m'],
        ];
    }
    $dtrForms[] = [
        'name' => $name,
        'official_regular' => $bundle['official_regular'],
        'official_saturday' => $bundle['official_saturday'],
        'in_charge' => $bundle['in_charge'] ?: 'HR',
        'rows' => $rows,
    ];
}

// One employee per page: left and right are duplicate CS Form 48 copies (same name and data).
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Employees DTR — <?php echo htmlspecialchars($monthLabel); ?></title>
    <style>
        /* Appendix 24 style: pair of CS Form 48 fills A4 portrait with minimal margin */
        @page { size: A4 portrait; margin: 4mm 4.5mm; }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; padding: 0; font-family: "Times New Roman", Times, serif; color: #000; background: #fff; }
        .no-print { padding: 12px; text-align: right; background: #f0f0f0; border-bottom: 1px solid #ccc; }
        .no-print button { padding: 8px 16px; margin-left: 8px; cursor: pointer; background: #007bff; color: #fff; border: none; border-radius: 4px; }
        .no-print button:hover { background: #0056b3; }

        /* Printable sheet: full A4; browser applies @page margins to this box */
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            height: 297mm;
            padding: 0;
            margin: 0 auto;
            page-break-after: always;
            page-break-inside: avoid;
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: stretch;
            justify-content: space-between;
            gap: 3mm;
        }
        .a4-page:last-child { page-break-after: auto; }

        .dtr-form-wrap,
        .dtr-form-spacer {
            flex: 1 1 calc(50% - 1.5mm);
            min-width: 0;
            min-height: 100%;
        }

        .dtr-form-wrap {
            display: flex;
            flex-direction: column;
            font-family: "Times New Roman", Times, serif;
            color: #000;
            background: #fff;
            padding: 1.5mm 2mm;
            border: 0.35pt solid #000;
            font-size: 6pt;
        }

        .dtr-form-header {
            flex: 0 0 auto;
        }

        .dtr-table-container {
            flex: 1 1 0;
            min-height: 0;
            width: 100%;
            position: relative;
        }

        .dtr-form-footer {
            flex: 0 0 auto;
            margin-top: 1mm;
        }

        .dtr-form-title { font-size: 7pt; font-weight: 700; text-align: center; margin: 0; line-height: 1.1; }
        .dtr-form-subtitle { font-size: 7pt; font-weight: 700; text-align: center; margin: 0; line-height: 1.1; }
        .dtr-form-line { text-align: center; font-size: 6pt; letter-spacing: 0.12em; margin: 0.5mm 0 1mm; line-height: 1.1; }
        .dtr-field-row { font-size: 6pt; margin: 0 0 0.4mm; line-height: 1.15; }
        .dtr-field-inline {
            display: inline-block;
            border-bottom: 0.35pt solid #000;
            min-width: 0;
            width: 72%;
            max-width: calc(100% - 4em);
            margin-left: 1mm;
            padding: 0 0.5mm;
            vertical-align: bottom;
        }
        .dtr-official-row { font-size: 5.5pt; margin: 0; line-height: 1.2; }

        .dtr-table {
            width: 100%;
            height: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            border-spacing: 0;
            font-size: 6pt;
        }

        .dtr-table th,
        .dtr-table td {
            padding: 0 0.5mm;
            vertical-align: middle;
            border: 0.35pt solid #000;
            line-height: 1.05;
        }

        .dtr-table th { background: #fff; font-weight: 700; text-align: center; font-size: 5.5pt; }
        .dtr-table .dtr-day { width: 7%; text-align: center; }
        .dtr-table .dtr-time { width: 14%; text-align: center; }
        .dtr-table .dtr-undertime { width: 10%; text-align: center; }
        .dtr-table tbody tr.dtr-total { font-weight: 700; }
        .dtr-table tr.dtr-invalid td { color: #bbb; }

        .dtr-table thead { display: table-header-group; }
        .dtr-table tbody { display: table-row-group; }
        .dtr-table thead tr th { padding-top: 0.35mm; padding-bottom: 0.35mm; }
        /* Screen preview: modest row height */
        .dtr-table tbody tr { height: auto; min-height: 4.5mm; }

        .dtr-certify { font-size: 5pt; margin: 0 0 0.5mm; line-height: 1.15; text-align: justify; }
        .dtr-verified { font-size: 5pt; margin: 0; line-height: 1.15; }
        .dtr-verified .dtr-incharge { display: block; font-weight: 700; margin-top: 0.5mm; }

        .dtr-form-spacer {
            border: 0.35pt solid transparent;
            visibility: hidden;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white; margin: 0; padding: 0; }
            .a4-page {
                width: 100%;
                max-width: 100%;
                height: 289mm; /* ~297mm A4 − @page margins */
                min-height: 289mm;
                margin: 0;
            }
            .dtr-form-wrap { padding: 1mm 1.5mm; }
            /* 32 rows × ~7.08mm ≈ 227mm body + header/footer fits printable height (Appendix 24 density) */
            .dtr-table tbody tr {
                height: 7.08mm;
                min-height: 7.08mm;
                max-height: 7.08mm;
            }
            .dtr-table thead tr { height: 4.5mm; min-height: 4.5mm; }
        }

        @media screen {
            body { padding-bottom: 24px; }
            .a4-page { box-shadow: 0 0 6px rgba(0,0,0,0.12); margin-bottom: 16px; height: auto; min-height: 297mm; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <button type="button" onclick="window.close()">Close</button>
        <span style="margin-left: 12px; color: #666;">
            <?php echo count($dtrForms); ?> employee(s) — <?php echo htmlspecialchars($monthLabel); ?> — each page: 2 identical DTR copies per employee (no pardon column)
        </span>
    </div>

    <?php foreach ($dtrForms as $form): ?>
        <div class="a4-page">
            <?php for ($copy = 0; $copy < 2; $copy++): ?>
                <div class="dtr-form-wrap">
                    <div class="dtr-form-header">
                        <div class="dtr-form-title">Civil Service Form No. 48</div>
                        <div class="dtr-form-subtitle">DAILY TIME RECORD</div>
                        <div class="dtr-form-line">-----o0o-----</div>
                        <div class="dtr-field-row">(Name) <span class="dtr-field-inline"><?php echo htmlspecialchars($form['name']); ?></span></div>
                        <div class="dtr-field-row">For the month of <span class="dtr-field-inline"><?php echo htmlspecialchars($monthLabel); ?></span></div>
                        <div class="dtr-official-row">Official hours for arrival and departure</div>
                        <div class="dtr-official-row">Regular days <span class="dtr-field-inline" style="width:62%"><?php echo htmlspecialchars($form['official_regular']); ?></span></div>
                        <div class="dtr-official-row">Saturdays <span class="dtr-field-inline" style="width:62%"><?php echo htmlspecialchars($form['official_saturday']); ?></span></div>
                    </div>
                    <div class="dtr-table-container">
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
                            <?php foreach ($form['rows'] as $r): ?>
                                <tr class="<?php echo empty($r['valid']) ? 'dtr-invalid' : ''; ?>">
                                    <td class="dtr-day"><?php echo (int) $r['day']; ?></td>
                                    <td class="dtr-time"><?php echo empty($r['valid']) ? '' : htmlspecialchars($r['time_in']); ?></td>
                                    <td class="dtr-time"><?php echo empty($r['valid']) ? '' : htmlspecialchars($r['lunch_out']); ?></td>
                                    <td class="dtr-time"><?php echo empty($r['valid']) ? '' : htmlspecialchars($r['lunch_in']); ?></td>
                                    <td class="dtr-time"><?php echo empty($r['valid']) ? '' : htmlspecialchars($r['time_out']); ?></td>
                                    <td class="dtr-undertime"><?php echo empty($r['valid']) ? '' : htmlspecialchars($r['ut_h']); ?></td>
                                    <td class="dtr-undertime"><?php echo empty($r['valid']) ? '' : htmlspecialchars($r['ut_m']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="dtr-total">
                                <td class="dtr-day">Total</td>
                                <td></td><td></td><td></td><td></td><td></td><td></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <div class="dtr-form-footer">
                        <p class="dtr-certify">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>
                        <p class="dtr-verified">VERIFIED as to the prescribed office hours:<br>In Charge<br><strong class="dtr-incharge"><?php echo htmlspecialchars($form['in_charge']); ?></strong></p>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($dtrForms)): ?>
        <div class="a4-page">
            <p style="padding: 20px; text-align: center;">No employees found.</p>
        </div>
    <?php endif; ?>
</body>
</html>
