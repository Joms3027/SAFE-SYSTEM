<?php
/**
 * Submit Travel Activity Request Form (TARF).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/tarf_form_options.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';

requireFaculty();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!validateFormToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please reload the page.']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
if (!$tbl || $tbl->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'TARF is not available yet. Run: php db/migrations/run_tarf_ntarf_migrations.php']);
    exit;
}

$stmt = $db->prepare(
    "SELECT fp.employee_id, u.first_name, u.last_name, u.email AS user_email
     FROM faculty_profiles fp
     INNER JOIN users u ON fp.user_id = u.id
     WHERE fp.user_id = ? LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    echo json_encode(['success' => false, 'message' => 'Profile not found.']);
    exit;
}

$wfCol = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote('endorser_target_user_id'));
if (!$wfCol || $wfCol->rowCount() === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'TARF routing is not installed. Run: php db/migrations/run_tarf_ntarf_migrations.php',
    ]);
    exit;
}
$fundCol = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote('fund_availability_target_user_id'));
if (!$fundCol || $fundCol->rowCount() === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Parallel endorsement columns are missing. Run: php db/migrations/run_tarf_ntarf_migrations.php',
    ]);
    exit;
}

if (empty(trim($profile['employee_id'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Your profile must have a Safe Employee ID for TARF supervisor routing.']);
    exit;
}

$supervisorIds = function_exists('getOpenerUserIdsForEmployee')
    ? getOpenerUserIdsForEmployee($profile['employee_id'], $db)
    : [];
if (empty($supervisorIds)) {
    echo json_encode([
        'success' => false,
        'message' => 'No supervisor (pardon opener) is assigned to your department or designation. Contact HR before submitting a TARF.',
    ]);
    exit;
}

$opts = tarf_get_form_options();
$collegeSet = array_flip($opts['colleges']);
$purposeSet = array_flip($opts['travel_purpose_types']);
$endorserSet = array_flip($opts['endorsers']);
$fundChargedSet = array_flip($opts['funding_charged']);
$specSet = array_flip($opts['funding_specifiers']);
// fund_endorser_role is already id => label; form posts the id (e.g. budget_101_164). Do not array_flip.

$requesterName = trim($_POST['requester_name'] ?? '');
$requesterEmail = trim($_POST['requester_email'] ?? '');
$collegeOffice = trim($_POST['college_office'] ?? '');
$collegeOther = trim($_POST['college_office_other'] ?? '');
$travelPurposeType = trim($_POST['travel_purpose_type'] ?? '');
$eventPurpose = trim($_POST['event_purpose'] ?? '');
$personsToTravelOther = trim($_POST['persons_to_travel_other'] ?? '');
$rawTravelUserIds = $_POST['persons_to_travel_user_ids'] ?? [];
if (!is_array($rawTravelUserIds)) {
    $rawTravelUserIds = [];
}
$travelUserIds = [];
foreach ($rawTravelUserIds as $tid) {
    $tid = (int) $tid;
    if ($tid > 0) {
        $travelUserIds[$tid] = true;
    }
}
$travelUserIds = array_keys($travelUserIds);
$destination = trim($_POST['destination'] ?? '');
$justification = trim($_POST['justification'] ?? '');
$cosJo = trim($_POST['cos_jo'] ?? '');
$dateDeparture = trim($_POST['date_departure'] ?? '');
$dateReturn = trim($_POST['date_return'] ?? '');
$applicableEndorser = trim($_POST['applicable_endorser'] ?? '');
$supervisorEmail = trim($_POST['supervisor_email'] ?? '');
$travelRequestType = trim($_POST['travel_request_type'] ?? '');
// University funding question removed from TARF form; persist as no for new submissions.
$universityFunding = 'no';
$fundingChargedTo = trim($_POST['funding_charged_to'] ?? '');
$fundingSpecifier = trim($_POST['funding_specifier'] ?? '');
$fundingSpecifierOther = trim($_POST['funding_specifier_other'] ?? '');
$endorserFundAvailability = trim($_POST['endorser_fund_availability'] ?? '');
$totalEstimatedAmount = trim($_POST['total_estimated_amount'] ?? '');

$publicity = $_POST['publicity'] ?? [];
if (!is_array($publicity)) {
    $publicity = [];
}
$publicity = array_values(array_unique(array_map('strval', $publicity)));

$travelSupport = $_POST['support_travel'] ?? [];
if (!is_array($travelSupport)) {
    $travelSupport = [];
}
$travelSupport = array_values(array_unique(array_map('strval', $travelSupport)));

$supportTravelOther = trim($_POST['support_travel_other'] ?? '');
$publicityOther = trim($_POST['publicity_other'] ?? '');

if ($requesterName === '' || $requesterEmail === '' || $eventPurpose === '' || $destination === '' || $justification === '') {
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
    exit;
}

$personLines = [];
if (!empty($travelUserIds)) {
    $placeholders = implode(',', array_fill(0, count($travelUserIds), '?'));
    $stTravel = $db->prepare(
        "SELECT fp.user_id, fp.designation, fp.position, u.first_name, u.last_name, u.user_type
         FROM faculty_profiles fp
         INNER JOIN users u ON u.id = fp.user_id
         WHERE fp.user_id IN ($placeholders) AND u.user_type IN ('faculty', 'staff') AND u.is_active = 1"
    );
    $stTravel->execute($travelUserIds);
    $foundTravel = [];
    while ($tr = $stTravel->fetch(PDO::FETCH_ASSOC)) {
        $foundTravel[(int) $tr['user_id']] = $tr;
    }
    foreach ($travelUserIds as $tuid) {
        if (!isset($foundTravel[$tuid])) {
            echo json_encode(['success' => false, 'message' => 'One or more selected travelers are invalid. Reload the form and try again.']);
            exit;
        }
        $personLines[] = tarf_travel_person_display_line($foundTravel[$tuid]);
    }
}
if ($personsToTravelOther !== '') {
    $personLines[] = $personsToTravelOther;
}
$personsToTravel = implode("\n", $personLines);
if (trim($personsToTravel) === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Select at least one person from the directory, or enter name(s) under Additional travelers.',
    ]);
    exit;
}

if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) || !filter_var($supervisorEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid requester email and supervisor / unit email.']);
    exit;
}

if ($collegeOffice === '__other__') {
    if ($collegeOther === '') {
        echo json_encode(['success' => false, 'message' => 'Please specify the college, office, or project.']);
        exit;
    }
    $collegeDisplay = $collegeOther;
} else {
    if (!isset($collegeSet[$collegeOffice])) {
        echo json_encode(['success' => false, 'message' => 'Invalid college/office selection.']);
        exit;
    }
    $collegeDisplay = $collegeOffice;
}

if (!isset($purposeSet[$travelPurposeType])) {
    echo json_encode(['success' => false, 'message' => 'Invalid type of travel activity.']);
    exit;
}

if (!isset($opts['cos_jo_options'][$cosJo])) {
    echo json_encode(['success' => false, 'message' => 'Please answer the COS/JO status question.']);
    exit;
}

if ($dateDeparture === '' || $dateReturn === '') {
    echo json_encode(['success' => false, 'message' => 'Departure and return dates are required.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDeparture) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReturn)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit;
}

if ($dateDeparture > $dateReturn) {
    echo json_encode(['success' => false, 'message' => 'Return date must be on or after departure date.']);
    exit;
}

if (!isset($endorserSet[$applicableEndorser])) {
    echo json_encode(['success' => false, 'message' => 'Please select an applicable endorser.']);
    exit;
}

$endorserTargetUserId = tarf_resolve_endorser_target_user_id($applicableEndorser, $db);
if ($endorserTargetUserId === null || $endorserTargetUserId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'The applicable endorser you selected (' . $applicableEndorser . ') is not linked to a portal user yet. The administrator must set the matching user ID in includes/tarf_endorser_user_map.php (or add a row to table tarf_endorser_route).',
    ]);
    exit;
}

$allowedPub = array_keys($opts['publicity_support']);
$publicity = array_values(array_filter($publicity, function ($k) use ($allowedPub) {
    return in_array($k, $allowedPub, true);
}));
if (count($publicity) === 0) {
    echo json_encode(['success' => false, 'message' => 'Select at least one publicity / support option (or N/A).']);
    exit;
}

if (!isset($opts['travel_request_type'][$travelRequestType])) {
    echo json_encode(['success' => false, 'message' => 'Select the type of travel requested.']);
    exit;
}

$allowedTravelSup = array_keys($opts['travel_support']);
$travelSupport = array_values(array_filter($travelSupport, function ($k) use ($allowedTravelSup) {
    return in_array($k, $allowedTravelSup, true);
}));
if (count($travelSupport) === 0 && $supportTravelOther === '') {
    echo json_encode(['success' => false, 'message' => 'Select at least one requested support item or describe other support.']);
    exit;
}

$needsFundingDetail = ($travelRequestType === 'official_business');

if ($needsFundingDetail) {
    if (!isset($fundChargedSet[$fundingChargedTo])) {
        echo json_encode(['success' => false, 'message' => 'Funding charged to is required for official business travel.']);
        exit;
    }
    if ($fundingSpecifier === '__other__') {
        if ($fundingSpecifierOther === '') {
            echo json_encode(['success' => false, 'message' => 'Specify the funding source or project name.']);
            exit;
        }
        $fundingSpecifierDisplay = $fundingSpecifierOther;
    } else {
        if (!isset($specSet[$fundingSpecifier])) {
            echo json_encode(['success' => false, 'message' => 'Select a funding specifier.']);
            exit;
        }
        $fundingSpecifierDisplay = $fundingSpecifier;
    }
    if (!isset($opts['fund_endorser_role'][$endorserFundAvailability])) {
        echo json_encode(['success' => false, 'message' => 'Select endorser for fund availability (Budget or Accounting).']);
        exit;
    }
    if ($totalEstimatedAmount === '') {
        echo json_encode(['success' => false, 'message' => 'Total estimated amount is required for official business travel.']);
        exit;
    }
} else {
    $fundingSpecifierDisplay = $fundingSpecifier === '__other__' ? $fundingSpecifierOther : $fundingSpecifier;
    if ($fundingSpecifier !== '' && $fundingSpecifier !== '__other__' && !isset($specSet[$fundingSpecifier])) {
        $fundingSpecifierDisplay = '';
    }
    if ($fundingSpecifier === '__other__' && $fundingSpecifierOther !== '') {
        $fundingSpecifierDisplay = $fundingSpecifierOther;
    }
}

$fundAvailabilityTargetUserId = null;
if ($needsFundingDetail) {
    $fundAvailabilityTargetUserId = tarf_resolve_fund_availability_endorser_user_id($endorserFundAvailability, $db);
    if ($fundAvailabilityTargetUserId === null || $fundAvailabilityTargetUserId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'No active profile is designated as University Budget Office or Officer in Charge University Accountant for your fund-availability choice. Contact HR.',
        ]);
        exit;
    }
}

$uploader = new FileUploader();
$allowedTypes = ALLOWED_FILE_TYPES;
$attachments = [];

$addFiles = function (array $fileStruct, string $role, int $maxFiles) use ($uploader, $allowedTypes, &$attachments) {
    if (empty($fileStruct['name'])) {
        return;
    }
    $names = $fileStruct['name'];
    $isMultiple = is_array($names);
    $n = $isMultiple ? count($names) : 1;
    $n = min($n, $maxFiles);
    for ($i = 0; $i < $n; $i++) {
        $file = [
            'name' => $isMultiple ? $names[$i] : $names,
            'type' => $isMultiple ? $fileStruct['type'][$i] : $fileStruct['type'],
            'tmp_name' => $isMultiple ? $fileStruct['tmp_name'][$i] : $fileStruct['tmp_name'],
            'error' => $isMultiple ? $fileStruct['error'][$i] : $fileStruct['error'],
            'size' => $isMultiple ? $fileStruct['size'][$i] : $fileStruct['size'],
        ];
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            continue;
        }
        $result = $uploader->uploadFile($file, 'tarf_requests', $allowedTypes, MAX_FILE_SIZE);
        if (!$result['success']) {
            throw new RuntimeException($result['message'] ?? 'Upload failed.');
        }
        $attachments[] = [
            'role' => $role,
            'path' => $result['file_path'],
            'original_name' => $result['original_filename'] ?? $file['name'],
        ];
    }
};

try {
    if (!empty($_FILES['supporting_documents']['name'])) {
        $addFiles($_FILES['supporting_documents'], 'supporting', 10);
    }
    if (!empty($_FILES['itinerary_file']['name'])) {
        $addFiles($_FILES['itinerary_file'], 'itinerary', 1);
    }
    if (!empty($_FILES['lib_file']['name'])) {
        $addFiles($_FILES['lib_file'], 'lib', 1);
    }
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$serialYear = (int) date('Y');

$formData = [
    'form_kind' => 'tarf',
    'requester_name' => $requesterName,
    'requester_email' => $requesterEmail,
    'college_office_project' => $collegeDisplay,
    'travel_purpose_type' => $travelPurposeType,
    'event_purpose' => $eventPurpose,
    'persons_to_travel' => $personsToTravel,
    'persons_to_travel_user_ids' => $travelUserIds,
    'persons_to_travel_other' => $personsToTravelOther !== '' ? $personsToTravelOther : null,
    'destination' => $destination,
    'justification' => $justification,
    'cos_jo' => $cosJo,
    'date_departure' => $dateDeparture,
    'date_return' => $dateReturn,
    'applicable_endorser' => $applicableEndorser,
    'supervisor_email' => $supervisorEmail,
    'publicity' => $publicity,
    'publicity_other' => $publicityOther,
    'travel_request_type' => $travelRequestType,
    'university_funding_requested' => $universityFunding,
    'support_travel' => $travelSupport,
    'support_travel_other' => $supportTravelOther,
    'funding_charged_to' => $needsFundingDetail ? $fundingChargedTo : null,
    'funding_specifier' => $fundingSpecifierDisplay !== '' ? $fundingSpecifierDisplay : null,
    'endorser_fund_availability' => $needsFundingDetail ? $endorserFundAvailability : null,
    'total_estimated_amount' => $needsFundingDetail ? $totalEstimatedAmount : ($totalEstimatedAmount !== '' ? $totalEstimatedAmount : null),
    'submitted_at_server' => date('Y-m-d H:i:s'),
];

$encForm = json_encode($formData, JSON_UNESCAPED_UNICODE);
$encAttach = count($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null;

if ($encForm === false) {
    echo json_encode(['success' => false, 'message' => 'Could not save form data.']);
    exit;
}

$ins = $db->prepare(
    'INSERT INTO tarf_requests (user_id, employee_id, serial_year, form_data, attachments, status, endorser_target_user_id, fund_availability_target_user_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$ins->execute([
    (int) $_SESSION['user_id'],
    $profile['employee_id'] ?: null,
    $serialYear,
    $encForm,
    $encAttach,
    'pending_joint',
    $endorserTargetUserId,
    $fundAvailabilityTargetUserId,
]);

$newId = (int) $db->lastInsertId();
$viewUrl = clean_url(getBasePath() . '/faculty/tarf_request_view.php?id=' . $newId, getBasePath());

echo json_encode([
    'success' => true,
    'message' => 'Your TARF was submitted. Your supervisor (pardon opener), applicable endorser, and Budget/Accounting (when applicable) may endorse it in parallel before it goes to the President for final approval.',
    'id' => $newId,
    'view_url' => $viewUrl,
]);
