<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

// Allow admin to pass ?id= to print any PDS; otherwise faculty prints their own latest
$database = Database::getInstance();
$db = $database->getConnection();

$requestedId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pds = null;

if ($requestedId && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    // Admin printing specific PDS
    $stmt = $db->prepare("SELECT p.*, u.first_name, u.last_name, u.email, fp.employee_id FROM faculty_pds p JOIN users u ON p.faculty_id = u.id LEFT JOIN faculty_profiles fp ON u.id = fp.user_id WHERE p.id = ?");
    $stmt->execute([$requestedId]);
    $pds = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Faculty prints their own PDS
    if (!isset($_SESSION['user_id'])) {
        echo "Unauthorized";
        exit;
    }
    $stmt = $db->prepare("SELECT p.*, u.first_name, u.last_name, u.email, fp.employee_id FROM faculty_pds p JOIN users u ON p.faculty_id = u.id LEFT JOIN faculty_profiles fp ON u.id = fp.user_id WHERE p.faculty_id = ? ORDER BY p.created_at DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $pds = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$pds) {
    echo "PDS record not found.";
    exit;
}

// Fetch normalized child rows
$childStmt = $db->prepare("SELECT name, dob FROM pds_children WHERE pds_id = ? ORDER BY id");
$childStmt->execute([$pds['id']]);
$pds['children'] = $childStmt->fetchAll(PDO::FETCH_ASSOC);

try {
    $eduStmt = $db->prepare("
        SELECT 
            level, 
            school, 
            degree, 
            from_date,
            to_date,
            units_earned,
            year_graduated,
            academic_honors 
        FROM pds_education 
        WHERE pds_id = ? 
        ORDER BY id
    ");
    $eduStmt->execute([$pds['id']]);
    $pds['education'] = $eduStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $eduStmt = $db->prepare("SELECT level, school, degree FROM pds_education WHERE pds_id = ? ORDER BY id");
    $eduStmt->execute([$pds['id']]);
    $pds['education'] = $eduStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($pds['education'])) {
    $raw_edu_json = $pds['educational_background'] ?? null;
    if (!empty($raw_edu_json)) {
        $decodedEdu = json_decode(is_string($raw_edu_json) ? $raw_edu_json : '[]', true);
        if (is_array($decodedEdu) && !empty($decodedEdu)) {
            $pds['education'] = array_map(function($row) {
                if (!is_array($row)) return [];
                return [
                    'level' => $row['level'] ?? ($row['type'] ?? ''),
                    'school' => $row['school'] ?? '',
                    'degree' => $row['degree'] ?? ($row['course'] ?? ''),
                    'year_graduated' => $row['year_graduated'] ?? ($row['year'] ?? ''),
                    'from_date' => $row['from_date'] ?? '',
                    'to_date' => $row['to_date'] ?? '',
                    'units_earned' => $row['units_earned'] ?? '',
                    'academic_honors' => $row['academic_honors'] ?? ''
                ];
            }, $decodedEdu);
        }
    }
}

try {
    $expStmt = $db->prepare("
        SELECT dates, position, company, salary, employment_status, salary_grade, appointment_status, gov_service
        FROM pds_experience 
        WHERE pds_id = ? 
        ORDER BY id
    ");
    $expStmt->execute([$pds['id']]);
    $pds['experience'] = $expStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expStmt = $db->prepare("SELECT dates, position, company, salary FROM pds_experience WHERE pds_id = ? ORDER BY id");
    $expStmt->execute([$pds['id']]);
    $pds['experience'] = $expStmt->fetchAll(PDO::FETCH_ASSOC);
}

$volStmt = $db->prepare("SELECT org, dates, hours, position FROM pds_voluntary WHERE pds_id = ? ORDER BY id");
$volStmt->execute([$pds['id']]);
$pds['voluntary'] = $volStmt->fetchAll(PDO::FETCH_ASSOC);

try {
    $ldStmt = $db->prepare("
        SELECT 
            title, 
            dates, 
            hours, 
            type, 
            conducted_by,
            has_certificate,
            venue,
            certificate_details
        FROM pds_learning 
        WHERE pds_id = ? 
        ORDER BY id
    ");
    $ldStmt->execute([$pds['id']]);
    $pds['learning'] = $ldStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $ldStmt = $db->prepare("SELECT title, dates, hours FROM pds_learning WHERE pds_id = ? ORDER BY id");
    $ldStmt->execute([$pds['id']]);
    $pds['learning'] = $ldStmt->fetchAll(PDO::FETCH_ASSOC);
}

$refStmt = $db->prepare("SELECT name, address, phone FROM pds_references WHERE pds_id = ? ORDER BY id");
$refStmt->execute([$pds['id']]);
$pds['references'] = $refStmt->fetchAll(PDO::FETCH_ASSOC);

// Decode additional_questions JSON
if (!empty($pds['additional_questions'])) {
    $decoded = json_decode($pds['additional_questions'], true);
    $pds['additional_questions'] = $decoded ?: [];
} else {
    $pds['additional_questions'] = [];
}

// Decode other_info JSON and merge additional fields into $pds for display
if (!empty($pds['other_info'])) {
    $decoded = json_decode($pds['other_info'], true);
    $pds['other_info'] = $decoded ?: [];
    $additionalFields = [
        'dual_citizenship_country', 'umid_id', 'philsys_number',
        'residential_house_no', 'residential_street', 'residential_subdivision',
        'residential_barangay', 'residential_city', 'residential_province',
        'permanent_house_no', 'permanent_street', 'permanent_subdivision',
        'permanent_barangay', 'permanent_city', 'permanent_province',
        'spouse_name_extension', 'sworn_date',
        'government_id_number', 'government_id_issue_date', 'government_id_issue_place'
    ];
    foreach ($additionalFields as $field) {
        if (isset($pds['other_info'][$field])) {
            $pds[$field] = $pds['other_info'][$field];
        }
    }
    // agency_employee_id: fallback to other_info if column empty (pre-migration)
    if (empty($pds['agency_employee_id']) && isset($pds['other_info']['agency_employee_id'])) {
        $pds['agency_employee_id'] = $pds['other_info']['agency_employee_id'];
    }
} else {
    $pds['other_info'] = [];
}

// Load civil service eligibility rows
$raw_cs_json = $pds['civil_service_eligibility'] ?? null;
try {
    $csStmt = $db->prepare("SELECT id, title, rating, date_of_exam, place_of_exam, license_number, date_of_validity FROM faculty_civil_service_eligibility WHERE pds_id = ? ORDER BY id");
    $csStmt->execute([$pds['id']]);
    $csRows = $csStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($csRows)) {
        $pds['civil_service_eligibility'] = $csRows;
    } else {
        $pds['civil_service_eligibility'] = json_decode(is_string($raw_cs_json) ? $raw_cs_json : '[]', true) ?: [];
    }
} catch (Exception $e) {
    $pds['civil_service_eligibility'] = json_decode(is_string($raw_cs_json) ? $raw_cs_json : '[]', true) ?: [];
}

// Helper function to display value or N/A
function displayValue($value, $default = '') {
    return !empty($value) ? htmlspecialchars($value) : ($default ?: 'N/A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CS Form No. 212 Revised 2025 - Personal Data Sheet</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <style>
        :root { 
            --border-color: #222; 
            --muted: #666; 
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            margin: 20px;
            font-size: 11pt;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
        }
        header { margin-bottom: 14px; }
        .hdr-table { width: 100%; border-collapse: collapse; }
        .hdr-left { width: 18%; vertical-align: middle; }
        .hdr-center { width: 64%; text-align: center; vertical-align: middle; }
        .hdr-right { width: 18%; text-align: right; vertical-align: middle; }
        .hdr-center h1 { 
            font-family: Georgia, 'Times New Roman', Times, serif; 
            font-size: 16pt; 
            margin: 4px 0; 
            font-weight: bold;
        }
        .hdr-center h2 { 
            font-family: Georgia, 'Times New Roman', Times, serif; 
            font-size: 13pt; 
            margin: 2px 0; 
            font-weight: normal; 
        }
        .hdr-center .meta { font-size: 9pt; color: var(--muted); margin-top: 4px; }

        h3 {
            font-family: Georgia, 'Times New Roman', Times, serif;
            font-size: 12pt;
            margin: 18px 0 8px 0;
            padding: 8px 10px;
            background: #f7f7f7;
            border: 1px solid var(--border-color);
            font-weight: bold;
        }
        .section { margin: 12px 0; clear: both; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; page-break-inside: auto; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        td, th { 
            padding: 7px 9px; 
            vertical-align: top; 
            border: 1px solid var(--border-color); 
            font-size: 10.5pt; 
        }
        th { text-align: left; background: #fafafa; font-weight: bold; }
        .subtable th, .subtable td { border: 1px solid var(--border-color); }
        .subtable tbody tr:nth-child(odd) { background: #fbfbfb; }
        .small { font-size: 9.5pt; color: var(--muted); }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-muted { color: var(--muted); font-style: italic; }
        .form-number { font-size: 9pt; color: var(--muted); }
        .field-label { font-weight: bold; margin-right: 5px; }
        .field-value { display: inline-block; min-height: 18px; border-bottom: 1px solid #000; padding: 0 5px; }
        .signature-block { margin-top: 40px; }
        .sig-line { border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 8px; text-align: center; min-height: 50px; }
        .sig-sub { font-size: 10pt; text-align: center; margin-top: 8px; }
        .photo-box { 
            width: 150px; 
            height: 180px; 
            border: 2px solid #000; 
            display: inline-block; 
            vertical-align: top;
            background: #f9f9f9;
        }
        footer.fixed-footer { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            height: 28px; 
            font-size: 9pt; 
            color: var(--muted); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 4px 10px; 
            border-top: 1px solid #ddd; 
            background: #fff; 
        }
        .page-number:after { content: "Page " counter(page) " of " counter(pages); }
        .page-break { page-break-before: always; }
        .no-break { page-break-inside: avoid; }

        @media print {
            @page { margin: 0.6in; }
            body { margin: 0; }
            .no-print { display: none; }
            footer.fixed-footer { position: fixed; bottom: 0; }
            h3 { -webkit-print-color-adjust: exact; }
            th { -webkit-print-color-adjust: exact; }
            .page-break { page-break-before: always; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:10px;">
        <button onclick="window.print()" style="padding: 8px 15px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Print PDS</button>
        <a href="../logout.php" class="btn btn-danger ms-2 logout-btn" data-logout-url="../logout.php" onclick="event.preventDefault(); confirmLogout(this);" style="padding: 8px 12px; border-radius:4px; text-decoration:none; display:inline-block;">
            <i class="fas fa-sign-out-alt" style="margin-right:6px;"></i>Logout
        </a>
    </div>

    <header>
        <table class="hdr-table">
            <tr>
                <td class="hdr-left">
                    <?php if (defined('SITE_URL') && file_exists(__DIR__ . '/../assets/img/logo.png')): ?>
                        <img src="<?php echo SITE_URL; ?>/assets/img/logo.png" alt="logo" style="height:72px;"> 
                    <?php else: ?>
                        <div style="font-size:10pt; font-weight:bold;">WESTERN PHILIPPINES</div>
                        <div style="font-size:9pt; color:var(--muted);">University</div>
                    <?php endif; ?>
                </td>
                <td class="hdr-center">
                    <h1>PERSONAL DATA SHEET</h1>
                    <div class="meta">CS Form No. 212 Revised 2025</div>
                </td>
                <td class="hdr-right">
                    <div class="form-number">PDS ID: <strong><?php echo htmlspecialchars(str_pad($pds['id'],5,'0',STR_PAD_LEFT)); ?></strong></div>
                    <?php if (defined('SITE_URL') && file_exists(__DIR__ . '/../assets/img/seal.png')): ?>
                        <img src="<?php echo SITE_URL; ?>/assets/img/seal.png" alt="seal" style="height:72px;"> 
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </header>

    <!-- I. PERSONAL INFORMATION -->
    <section class="section">
        <h3>I. PERSONAL INFORMATION</h3>
        <table>
            <tr>
                <td style="width: 15%;"><strong>1.</strong></td>
                <td style="width: 25%;"><strong>SURNAME</strong></td>
                <td style="width: 60%;"><?php echo displayValue($pds['last_name'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>2.</strong></td>
                <td><strong>FIRST NAME</strong></td>
                <td>
                    <?php echo displayValue($pds['first_name'] ?? ''); ?>
                    <span style="margin-left: 20px;"><strong>NAME EXTENSION (JR., SR)</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['name_extension'] ?? ''); ?></span>
                </td>
            </tr>
            <tr>
                <td></td>
                <td><strong>MIDDLE NAME</strong></td>
                <td><?php echo displayValue($pds['middle_name'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>3.</strong></td>
                <td><strong>DATE OF BIRTH<br>(dd/mm/yyyy)</strong></td>
                <td><?php echo formatDate($pds['date_of_birth'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>4.</strong></td>
                <td><strong>PLACE OF BIRTH</strong></td>
                <td><?php echo displayValue($pds['place_of_birth'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>5.</strong></td>
                <td><strong>SEX AT BIRTH</strong></td>
                <td><?php echo displayValue($pds['sex'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>6.</strong></td>
                <td><strong>CIVIL STATUS</strong></td>
                <td><?php echo displayValue($pds['civil_status'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>7.</strong></td>
                <td><strong>HEIGHT (m)</strong></td>
                <td><?php echo displayValue($pds['height'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>8.</strong></td>
                <td><strong>WEIGHT (kg)</strong></td>
                <td><?php echo displayValue($pds['weight'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>9.</strong></td>
                <td><strong>BLOOD TYPE</strong></td>
                <td><?php echo displayValue($pds['blood_type'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>10.</strong></td>
                <td><strong>UMID ID NO.</strong></td>
                <td><?php echo displayValue($pds['umid_id'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>11.</strong></td>
                <td><strong>PAG-IBIG ID NO.</strong></td>
                <td><?php echo displayValue($pds['pagibig_id'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>12.</strong></td>
                <td><strong>PHILHEALTH NO.</strong></td>
                <td><?php echo displayValue($pds['philhealth_id'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>13.</strong></td>
                <td><strong>PhilSys Number (PSN):</strong></td>
                <td><?php echo displayValue($pds['philsys_number'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>14.</strong></td>
                <td><strong>TIN NO.</strong></td>
                <td><?php echo displayValue($pds['tin'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>15.</strong></td>
                <td><strong>AGENCY EMPLOYEE ID</strong></td>
                <td><?php echo displayValue($pds['agency_employee_id'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>16.</strong></td>
                <td><strong>CITIZENSHIP</strong></td>
                <td>
                    <?php echo displayValue($pds['citizenship'] ?? 'Filipino'); ?>
                    <?php if (!empty($pds['dual_citizenship_country'])): ?>
                        <br><small>If holder of dual citizenship, please indicate country: <?php echo htmlspecialchars($pds['dual_citizenship_country']); ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>17.</strong></td>
                <td><strong>RESIDENTIAL ADDRESS</strong></td>
                <td>
                    <?php 
                    $resAddr = [];
                    if (!empty($pds['residential_house_no'])) $resAddr[] = 'House/Block/Lot No.: ' . htmlspecialchars($pds['residential_house_no']);
                    if (!empty($pds['residential_street'])) $resAddr[] = 'Street: ' . htmlspecialchars($pds['residential_street']);
                    if (!empty($pds['residential_subdivision'])) $resAddr[] = 'Subdivision/Village: ' . htmlspecialchars($pds['residential_subdivision']);
                    if (!empty($pds['residential_barangay'])) $resAddr[] = 'Barangay: ' . htmlspecialchars($pds['residential_barangay']);
                    if (!empty($pds['residential_city'])) $resAddr[] = 'City/Municipality: ' . htmlspecialchars($pds['residential_city']);
                    if (!empty($pds['residential_province'])) $resAddr[] = 'Province: ' . htmlspecialchars($pds['residential_province']);
                    if (!empty($pds['residential_zipcode'])) $resAddr[] = 'ZIP CODE: ' . htmlspecialchars($pds['residential_zipcode']);
                    echo !empty($resAddr) ? implode('<br>', $resAddr) : displayValue($pds['residential_address'] ?? '');
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>18.</strong></td>
                <td><strong>PERMANENT ADDRESS</strong></td>
                <td>
                    <?php 
                    $permAddr = [];
                    if (!empty($pds['permanent_house_no'])) $permAddr[] = 'House/Block/Lot No.: ' . htmlspecialchars($pds['permanent_house_no']);
                    if (!empty($pds['permanent_street'])) $permAddr[] = 'Street: ' . htmlspecialchars($pds['permanent_street']);
                    if (!empty($pds['permanent_subdivision'])) $permAddr[] = 'Subdivision/Village: ' . htmlspecialchars($pds['permanent_subdivision']);
                    if (!empty($pds['permanent_barangay'])) $permAddr[] = 'Barangay: ' . htmlspecialchars($pds['permanent_barangay']);
                    if (!empty($pds['permanent_city'])) $permAddr[] = 'City/Municipality: ' . htmlspecialchars($pds['permanent_city']);
                    if (!empty($pds['permanent_province'])) $permAddr[] = 'Province: ' . htmlspecialchars($pds['permanent_province']);
                    if (!empty($pds['permanent_zipcode'])) $permAddr[] = 'ZIP CODE: ' . htmlspecialchars($pds['permanent_zipcode']);
                    echo !empty($permAddr) ? implode('<br>', $permAddr) : displayValue($pds['permanent_address'] ?? '');
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>19.</strong></td>
                <td><strong>TELEPHONE NO.</strong></td>
                <td><?php echo displayValue($pds['residential_telno'] ?? ($pds['permanent_telno'] ?? '')); ?></td>
            </tr>
            <tr>
                <td><strong>20.</strong></td>
                <td><strong>MOBILE NO.</strong></td>
                <td><?php echo displayValue($pds['mobile_no'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>21.</strong></td>
                <td><strong>E-MAIL ADDRESS (if any)</strong></td>
                <td><?php echo displayValue($pds['email'] ?? ''); ?></td>
            </tr>
        </table>
    </section>

    <!-- II. FAMILY BACKGROUND -->
    <section class="section">
        <h3>II. FAMILY BACKGROUND</h3>
        <table>
            <tr>
                <td style="width: 15%;"><strong>22.</strong></td>
                <td style="width: 85%;" colspan="2">
                    <strong>SPOUSE'S SURNAME</strong>
                    <span style="margin-left: 20px;"><?php echo displayValue($pds['spouse_last_name'] ?? ''); ?></span>
                    <span style="margin-left: 30px;"><strong>FIRST NAME</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['spouse_first_name'] ?? ''); ?></span>
                    <span style="margin-left: 20px;"><strong>NAME EXTENSION (JR., SR)</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['spouse_name_extension'] ?? ''); ?></span>
                </td>
            </tr>
            <tr>
                <td></td>
                <td style="width: 20%;"><strong>MIDDLE NAME</strong></td>
                <td><?php echo displayValue($pds['spouse_middle_name'] ?? ''); ?></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>OCCUPATION</strong></td>
                <td><?php echo displayValue($pds['spouse_occupation'] ?? ''); ?></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>EMPLOYER/BUSINESS NAME</strong></td>
                <td><?php echo displayValue($pds['spouse_employer'] ?? ''); ?></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>BUSINESS ADDRESS</strong></td>
                <td><?php echo displayValue($pds['spouse_business_address'] ?? ''); ?></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>TELEPHONE NO.</strong></td>
                <td><?php echo displayValue($pds['spouse_telno'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>23.</strong></td>
                <td colspan="2"><strong>NAME OF CHILDREN (Write full name and list all)</strong></td>
            </tr>
        </table>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 5%;">No.</th>
                    <th style="width: 60%;">Name (Last Name, First Name, Middle Name)</th>
                    <th style="width: 35%;">DATE OF BIRTH (dd/mm/yyyy)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['children'])): ?>
                    <tr><td colspan="3" class="text-center text-muted">No children listed</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($pds['children'] as $c): ?>
                        <tr>
                            <td class="text-center"><?php echo $i++; ?></td>
                            <td><?php echo displayValue($c['name'] ?? ''); ?></td>
                            <td class="text-center"><?php echo formatDate($c['dob'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
        <table>
            <tr>
                <td style="width: 15%;"><strong>24.</strong></td>
                <td style="width: 85%;" colspan="2">
                    <strong>FATHER'S SURNAME</strong>
                    <span style="margin-left: 20px;"><?php echo displayValue($pds['father_last_name'] ?? ''); ?></span>
                    <span style="margin-left: 30px;"><strong>FIRST NAME</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['father_first_name'] ?? ''); ?></span>
                    <span style="margin-left: 20px;"><strong>NAME EXTENSION (JR., SR)</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['father_name_extension'] ?? ''); ?></span>
                </td>
            </tr>
            <tr>
                <td></td>
                <td style="width: 20%;"><strong>MIDDLE NAME</strong></td>
                <td><?php echo displayValue($pds['father_middle_name'] ?? ''); ?></td>
            </tr>
            <tr>
                <td><strong>25.</strong></td>
                <td colspan="2">
                    <strong>MOTHER'S MAIDEN NAME</strong>
                    <span style="margin-left: 20px;"><strong>SURNAME</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['mother_last_name'] ?? ''); ?></span>
                    <span style="margin-left: 30px;"><strong>FIRST NAME</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['mother_first_name'] ?? ''); ?></span>
                    <span style="margin-left: 30px;"><strong>MIDDLE NAME</strong></span>
                    <span style="margin-left: 10px;"><?php echo displayValue($pds['mother_middle_name'] ?? ''); ?></span>
                </td>
            </tr>
        </table>
    </section>

    <!-- III. EDUCATIONAL BACKGROUND -->
    <section class="section">
        <h3>III. EDUCATIONAL BACKGROUND</h3>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 12%;">26. LEVEL</th>
                    <th style="width: 25%;">NAME OF SCHOOL</th>
                    <th style="width: 25%;">BASIC EDUCATION/DEGREE/COURSE</th>
                    <th style="width: 18%;">PERIOD OF ATTENDANCE</th>
                    <th style="width: 10%;">HIGHEST LEVEL/UNITS EARNED</th>
                    <th style="width: 10%;">YEAR GRADUATED</th>
                </tr>
                <tr>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th style="font-size: 9pt;">From</th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['education'])): ?>
                    <tr><td colspan="6" class="text-center text-muted">No educational background entries</td></tr>
                <?php else: ?>
                    <?php foreach ($pds['education'] as $e): ?>
                        <tr>
                            <td><?php echo displayValue($e['level'] ?? ''); ?></td>
                            <td><?php echo displayValue($e['school'] ?? ''); ?></td>
                            <td>
                                <?php echo displayValue($e['degree'] ?? ''); ?>
                                <?php if (!empty($e['academic_honors'])): ?>
                                    <br><small class="text-muted">Scholarship/Academic Honors: <?php echo htmlspecialchars($e['academic_honors']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $fromDate = !empty($e['from_date']) ? formatDate($e['from_date']) : '';
                                $toDate = !empty($e['to_date']) ? formatDate($e['to_date']) : 'Present';
                                echo $fromDate . ($fromDate ? ' - ' : '') . $toDate;
                                ?>
                            </td>
                            <td class="text-center"><?php echo displayValue($e['units_earned'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($e['year_graduated'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
    </section>

    <div class="page-break"></div>

    <!-- IV. CIVIL SERVICE ELIGIBILITY -->
    <section class="section">
        <h3>IV. CIVIL SERVICE ELIGIBILITY</h3>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 30%;">27. CES/CSEE/CAREER SERVICE/RA 1080 (BOARD/BAR)/UNDER SPECIAL LAWS/CATEGORY II/IV ELIGIBILITY and ELIGIBILITIES FOR UNIFORMED PERSONNEL</th>
                    <th style="width: 10%;">RATING<br>(If Applicable)</th>
                    <th style="width: 15%;">DATE OF EXAMINATION/<br>CONFERMENT</th>
                    <th style="width: 20%;">PLACE OF EXAMINATION/<br>CONFERMENT</th>
                    <th style="width: 15%;">LICENSE (if applicable)<br>NUMBER</th>
                    <th style="width: 10%;">Valid Until</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['civil_service_eligibility'])): ?>
                    <tr><td colspan="6" class="text-center text-muted">No civil service eligibility entries</td></tr>
                <?php else: ?>
                    <?php foreach ($pds['civil_service_eligibility'] as $cs): ?>
                        <tr>
                            <td><?php echo displayValue($cs['title'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($cs['rating'] ?? ''); ?></td>
                            <td class="text-center"><?php echo formatDate($cs['date_of_exam'] ?? ''); ?></td>
                            <td><?php echo displayValue($cs['place_of_exam'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($cs['license_number'] ?? ''); ?></td>
                            <td class="text-center"><?php echo formatDate($cs['date_of_validity'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
    </section>

    <!-- V. WORK EXPERIENCE -->
    <section class="section">
        <h3>V. WORK EXPERIENCE</h3>
        <p class="small" style="margin-bottom: 8px;"><em>(Include private employment. Start from your recent work. Description of duties should be indicated in the attached Work Experience Sheet.)</em></p>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 15%;">28. INCLUSIVE DATES<br>(dd/mm/yyyy)</th>
                    <th style="width: 25%;">POSITION TITLE<br>(Write in full/Do not abbreviate)</th>
                    <th style="width: 25%;">DEPARTMENT/AGENCY/OFFICE/COMPANY<br>(Write in full/Do not abbreviate)</th>
                    <th style="width: 12%;">STATUS OF<br>APPOINTMENT</th>
                    <th style="width: 8%;">GOV'T<br>SERVICE<br>(Y/N)</th>
                </tr>
                <tr>
                    <th style="font-size: 9pt;">From</th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['experience'])): ?>
                    <tr><td colspan="5" class="text-center text-muted">No work experience entries</td></tr>
                <?php else: ?>
                    <?php foreach ($pds['experience'] as $ex): ?>
                        <tr>
                            <td class="text-center">
                                <?php 
                                if (!empty($ex['dates'])) {
                                    echo htmlspecialchars($ex['dates']);
                                } else {
                                    // Try to parse dates if available
                                    $fromDate = !empty($ex['from_date']) ? formatDate($ex['from_date']) : '';
                                    $toDate = !empty($ex['to_date']) ? formatDate($ex['to_date']) : 'Present';
                                    echo $fromDate . ($fromDate ? ' - ' : '') . $toDate;
                                }
                                ?>
                            </td>
                            <td><?php echo displayValue($ex['position'] ?? ''); ?></td>
                            <td><?php echo displayValue($ex['company'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($ex['appointment_status'] ?? ($ex['employment_status'] ?? '')); ?></td>
                            <td class="text-center"><?php echo displayValue($ex['gov_service'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
    </section>

    <div class="page-break"></div>

    <!-- VI. VOLUNTARY WORK -->
    <section class="section">
        <h3>VI. VOLUNTARY WORK OR INVOLVEMENT IN CIVIC/NON-GOVERNMENT/PEOPLE/VOLUNTARY ORGANIZATION/S</h3>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 35%;">29. NAME & ADDRESS OF ORGANIZATION<br>(Write in full)</th>
                    <th style="width: 20%;">INCLUSIVE DATES<br>(dd/mm/yyyy)</th>
                    <th style="width: 12%;">NUMBER OF<br>HOURS</th>
                    <th style="width: 33%;">POSITION/NATURE OF WORK</th>
                </tr>
                <tr>
                    <th></th>
                    <th style="font-size: 9pt;">From</th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['voluntary'])): ?>
                    <tr><td colspan="4" class="text-center text-muted">No voluntary work entries</td></tr>
                <?php else: ?>
                    <?php foreach ($pds['voluntary'] as $v): ?>
                        <tr>
                            <td><?php echo displayValue($v['org'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($v['dates'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($v['hours'] ?? '0'); ?></td>
                            <td><?php echo displayValue($v['position'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
    </section>

    <!-- VII. LEARNING AND DEVELOPMENT -->
    <section class="section">
        <h3>VII. LEARNING AND DEVELOPMENT (L&D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED</h3>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 30%;">30. TITLE OF LEARNING AND DEVELOPMENT INTERVENTIONS/TRAINING PROGRAMS<br>(Write in full)</th>
                    <th style="width: 18%;">INCLUSIVE DATES OF ATTENDANCE<br>(dd/mm/yyyy)</th>
                    <th style="width: 10%;">NUMBER OF<br>HOURS</th>
                    <th style="width: 15%;">Type of L&D<br>(Managerial/Supervisory/Technical/etc)</th>
                    <th style="width: 27%;">CONDUCTED/SPONSORED BY<br>(Write in full)</th>
                </tr>
                <tr>
                    <th></th>
                    <th style="font-size: 9pt;">From</th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['learning'])): ?>
                    <tr><td colspan="5" class="text-center text-muted">No learning and development entries</td></tr>
                <?php else: ?>
                    <?php foreach ($pds['learning'] as $l): ?>
                        <tr>
                            <td><?php echo displayValue($l['title'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($l['dates'] ?? ''); ?></td>
                            <td class="text-center"><?php echo displayValue($l['hours'] ?? '0'); ?></td>
                            <td class="text-center"><?php echo displayValue($l['type'] ?? ''); ?></td>
                            <td><?php echo displayValue($l['conducted_by'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
    </section>

    <!-- VIII. OTHER INFORMATION -->
    <section class="section">
        <h3>VIII. OTHER INFORMATION</h3>
        <table>
            <tr>
                <td style="width: 15%;"><strong>31.</strong></td>
                <td style="width: 20%;"><strong>SPECIAL SKILLS and HOBBIES</strong></td>
                <td style="width: 65%;"><?php echo nl2br(displayValue($pds['other_info']['skills'] ?? '')); ?></td>
            </tr>
            <tr>
                <td><strong>32.</strong></td>
                <td><strong>NON-ACADEMIC DISTINCTIONS/RECOGNITION</strong></td>
                <td><?php echo nl2br(displayValue($pds['other_info']['distinctions'] ?? '')); ?></td>
            </tr>
            <tr>
                <td><strong>33.</strong></td>
                <td><strong>MEMBERSHIP IN ASSOCIATION/ORGANIZATION</strong></td>
                <td><?php echo nl2br(displayValue($pds['other_info']['memberships'] ?? '')); ?></td>
            </tr>
        </table>
        <p class="small">(Continue on separate sheet if necessary)</p>
        <div style="margin-top: 20px;">
            <strong>SIGNATURE</strong> (wet signature/e-signature/digital certificate)
            <div style="margin-top: 10px; text-align: right;">
                <strong>DATE</strong> <?php echo formatDate($pds['date_accomplished'] ?? ($pds['created_at'] ?? '')); ?>
            </div>
        </div>
        <p style="text-align: center; margin-top: 20px; font-size: 9pt; color: var(--muted);">
            <strong>CS FORM 212 (Revised 2025), Page 3 of 4</strong>
        </p>
    </section>

    <div class="page-break"></div>

    <!-- IX. ADDITIONAL INFORMATION -->
    <section class="section">
        <h3>IX. ADDITIONAL INFORMATION</h3>
        <table>
            <tr>
                <td style="width: 15%;"><strong>34.</strong></td>
                <td style="width: 85%;">
                    Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed:<br>
                    <span style="margin-left: 20px;">a. within the third degree?</span>
                    <span style="margin-left: 20px;"><?php echo !empty($pds['additional_questions']['related_authority_third']) ? 'YES' : 'NO'; ?></span><br>
                    <span style="margin-left: 20px;">b. within the fourth degree (for Local Government Unit - Career Employees)?</span>
                    <span style="margin-left: 20px;"><?php echo !empty($pds['additional_questions']['related_authority_fourth']) ? 'YES' : 'NO'; ?></span><br>
                    <?php if (!empty($pds['additional_questions']['related_authority_details'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['related_authority_details']); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>35.</strong></td>
                <td>
                    a. Have you ever been found guilty of any administrative offense?<br>
                    <?php if (!empty($pds['additional_questions']['found_guilty_admin'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['found_guilty_admin']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?><br>
                    b. Have you been criminally charged before any court?<br>
                    <?php if (!empty($pds['additional_questions']['criminally_charged'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['criminally_charged']); ?></span><br>
                        <?php if (!empty($pds['additional_questions']['criminal_charge_date'])): ?>
                            <span style="margin-left: 20px;">Date Filed: <?php echo htmlspecialchars($pds['additional_questions']['criminal_charge_date']); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($pds['additional_questions']['criminal_charge_status'])): ?>
                            <span style="margin-left: 20px;">Status of Case/s: <?php echo htmlspecialchars($pds['additional_questions']['criminal_charge_status']); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>36.</strong></td>
                <td>
                    Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?<br>
                    <?php if (!empty($pds['additional_questions']['convicted_crime'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['convicted_crime']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>37.</strong></td>
                <td>
                    Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?<br>
                    <?php if (!empty($pds['additional_questions']['separated_service'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['separated_service']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>38.</strong></td>
                <td>
                    a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?<br>
                    <?php if (!empty($pds['additional_questions']['candidate_election'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['candidate_election']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?><br>
                    b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a candidate or party?<br>
                    <?php if (!empty($pds['additional_questions']['resigned_for_election'])): ?>
                        <span style="margin-left: 20px;">If YES, give details: <?php echo htmlspecialchars($pds['additional_questions']['resigned_for_election']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>39.</strong></td>
                <td>
                    Have you acquired the status of an immigrant or permanent resident of another country?<br>
                    <?php if (!empty($pds['additional_questions']['immigrant_status'])): ?>
                        <span style="margin-left: 20px;">If YES, give details (country): <?php echo htmlspecialchars($pds['additional_questions']['immigrant_status']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>40.</strong></td>
                <td>
                    Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277); and (c) Solo Parents Welfare Act of 2000 (RA 8972):<br>
                    a. Are you a member of any indigenous group?<br>
                    <?php if (!empty($pds['additional_questions']['indigenous_group'])): ?>
                        <span style="margin-left: 20px;">If YES, please specify: <?php echo htmlspecialchars($pds['additional_questions']['indigenous_group']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?><br>
                    b. Are you a person with disability?<br>
                    <?php if (!empty($pds['additional_questions']['person_with_disability'])): ?>
                        <span style="margin-left: 20px;">If YES, please specify ID No: <?php echo htmlspecialchars($pds['additional_questions']['person_with_disability']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?><br>
                    c. Are you a solo parent?<br>
                    <?php if (!empty($pds['additional_questions']['solo_parent'])): ?>
                        <span style="margin-left: 20px;">If YES, please specify ID No: <?php echo htmlspecialchars($pds['additional_questions']['solo_parent']); ?></span>
                    <?php else: ?>
                        <span style="margin-left: 20px;">NO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>41.</strong></td>
                <td><strong>REFERENCES (Person not related by consanguinity or affinity to the appointee)</strong></td>
            </tr>
        </table>
        <table class="subtable">
            <thead>
                <tr>
                    <th style="width: 30%;">NAME</th>
                    <th style="width: 45%;">OFFICE/RESIDENTIAL ADDRESS</th>
                    <th style="width: 25%;">CONTACT NO. AND/OR EMAIL</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pds['references'])): ?>
                    <tr><td colspan="3" class="text-center text-muted">No references listed</td></tr>
                <?php else: ?>
                    <?php foreach ($pds['references'] as $r): ?>
                        <tr>
                            <td><?php echo displayValue($r['name'] ?? ''); ?></td>
                            <td><?php echo displayValue($r['address'] ?? ''); ?></td>
                            <td><?php echo displayValue($r['phone'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <!-- DECLARATION -->
    <section class="section no-break">
        <h3>42. DECLARATION</h3>
        <p style="text-align: justify; margin: 12px 0 20px 0; line-height: 1.6;">
            I declare under oath that I have personally accomplished this Personal Data Sheet and that all information is true and correct based on authentic records, documents and papers. I authorize the agency head/authorized representative to verify/validate the contents stated herein. I also agree that any misrepresentation made in this document and its attachments shall cause the filing of charges against me.
        </p>

        <table style="margin-top: 20px;">
            <tr>
                <td style="width: 30%; vertical-align: top;">
                    <strong>PHOTO</strong><br>
                    <div class="photo-box"></div>
                    <small>(2x2 ID picture)</small>
                </td>
                <td style="width: 70%; vertical-align: top;">
                    <strong>Government Issued ID</strong> (i.e. Passport, GSIS, SSS, PRC, Driver's License, etc.)<br>
                    <table style="border: none; margin-top: 10px;">
                        <tr>
                            <td style="border: none; padding: 5px 0;"><strong>ID/License/Passport No.:</strong> <?php echo displayValue($pds['government_id_number'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <td style="border: none; padding: 5px 0;"><strong>Date/Place of Issuance:</strong> <?php echo displayValue($pds['government_id_issue_date'] ?? ''); ?> <?php echo displayValue($pds['government_id_issue_place'] ?? ''); ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div style="margin-top: 30px;">
            <div style="display: flex; justify-content: space-between;">
                <div style="width: 45%;">
                    <strong>Signature</strong> (Sign inside the box)<br>
                    <div class="sig-line" style="width: 100%; min-height: 60px;"></div>
                    <div class="sig-sub">(wet signature/e-signature/digital certificate)</div>
                </div>
                <div style="width: 45%;">
                    <strong>Date Accomplished</strong><br>
                    <div style="border-top: 1px solid #000; padding-top: 8px; text-align: center; min-height: 40px; margin-top: 20px;">
                        <?php echo formatDate($pds['date_accomplished'] ?? ($pds['created_at'] ?? '')); ?>
                    </div>
                </div>
            </div>
            <div style="margin-top: 20px;">
                <strong>Right Thumbmark</strong><br>
                <div style="border: 1px solid #000; width: 100px; height: 80px; margin-top: 10px;"></div>
            </div>
        </div>

        <div style="margin-top: 40px; border-top: 1px solid #000; padding-top: 15px;">
            <p><strong>SUBSCRIBED AND SWORN to before me this</strong> <?php echo formatDate($pds['sworn_date'] ?? ''); ?></p>
            <div style="margin-top: 30px;">
                <div class="sig-line" style="width: 60%;"></div>
                <div class="sig-sub"><strong>Person Administering Oath</strong></div>
                <div class="sig-sub" style="font-size: 9pt;">(wet signature/e-signature/digital certificate except when administered by a notary public)</div>
            </div>
        </div>

        <p style="text-align: center; margin-top: 30px; font-size: 9pt; color: var(--muted);">
            <strong>CS FORM 212 (Revised 2025), Page 4 of 4</strong>
        </p>
    </section>

    <footer class="fixed-footer no-print">
        <div>Generated by WPU Faculty and Staff System</div>
        <div class="page-number"></div>
        <div>PDS ID: <?php echo htmlspecialchars(str_pad($pds['id'],5,'0',STR_PAD_LEFT)); ?></div>
    </footer>

    <?php
    // Get base path for assets
    $basePath = '';
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $pathSegments = array_filter(explode('/', $scriptPath));
        if (count($pathSegments) > 0) {
            $basePath = '/' . reset($pathSegments);
        }
    }
    if (empty($basePath) && isset($_SERVER['REQUEST_URI'])) {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($requestUri && $requestUri !== '/') {
            $uriSegments = array_filter(explode('/', $requestUri));
            if (count($uriSegments) > 0) {
                $basePath = '/' . reset($uriSegments);
            }
        }
    }
    if (empty($basePath)) {
        $basePath = '/FP';
    }
    ?>
    <!-- Load Bootstrap and main.js for logout functionality -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
</body>
</html>
