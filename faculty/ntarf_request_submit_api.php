<?php
/**
 * Submit [NON-TRAVEL] Activity Request Form (NTARF).
 * Routing matches TARF: supervisor (pardon opener), applicable endorser, and Budget or Accounting when funding certification applies — parallel endorsements (pending_joint), then President (pending_president).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/ntarf_form_options.php';
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
    echo json_encode(['success' => false, 'message' => 'NTARF is not available yet. Please ask the administrator to run the database migration.']);
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
        'message' => 'Request routing is not installed. Run db/migrations/run_add_tarf_workflow_columns.php on the server.',
    ]);
    exit;
}
$fundCol = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote('fund_availability_target_user_id'));
if (!$fundCol || $fundCol->rowCount() === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Parallel endorsement columns are missing. Run db/migrations/run_tarf_parallel_joint_endorsements.php on the server.',
    ]);
    exit;
}

if (empty(trim($profile['employee_id'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Your profile must have a Safe Employee ID for supervisor routing.']);
    exit;
}

$supervisorIds = function_exists('getOpenerUserIdsForEmployee')
    ? getOpenerUserIdsForEmployee($profile['employee_id'], $db)
    : [];
if (empty($supervisorIds)) {
    echo json_encode([
        'success' => false,
        'message' => 'No supervisor (pardon opener) is assigned to your department or designation. Contact HR before submitting an NTARF.',
    ]);
    exit;
}

$opts = ntarf_get_form_options();
$collegeSet = array_flip($opts['colleges']);
$endorserSet = array_flip($opts['endorsers']);
$fundChargedSet = array_flip($opts['funding_charged']);
$specSet = array_flip($opts['funding_specifiers']);
$supportSet = array_keys($opts['requested_support']);
$allowedPub = array_keys($opts['publicity_support']);
$activityCampusSet = array_flip($opts['activity_campuses']);
$venueSiteSet = array_flip($opts['venue_sites']);
$endorserVenueSet = array_flip($opts['endorser_venue_availability']);
$endorserElectricitySet = array_flip($opts['endorser_electricity']);
$involvementKeySet = array_keys($opts['involvement_types']);

$requesterName = trim($_POST['requester_name'] ?? '');
$requesterEmail = trim($_POST['requester_email'] ?? '');
$collegeOffice = trim($_POST['college_office'] ?? '');
$collegeOther = trim($_POST['college_office_other'] ?? '');
$activityRequested = trim($_POST['activity_requested'] ?? '');
$mainOrganizer = trim($_POST['main_organizer'] ?? '');
$justification = trim($_POST['justification'] ?? '');
$activityCampus = trim($_POST['activity_campus'] ?? '');
$activityCampusOther = trim($_POST['activity_campus_other'] ?? '');
$venueSite = trim($_POST['venue_site'] ?? '');
$venueSiteOther = trim($_POST['venue_site_other'] ?? '');
$dateActivityStart = trim($_POST['date_activity_start'] ?? '');
$dateActivityEnd = trim($_POST['date_activity_end'] ?? '');
$timeActivityStart = trim($_POST['time_activity_start'] ?? '');
$timeActivityEnd = trim($_POST['time_activity_end'] ?? '');

$involvementTypesPost = $_POST['involvement_types'] ?? [];
if (!is_array($involvementTypesPost)) {
    $involvementTypesPost = [];
}
$involvementTypesPost = array_values(array_unique(array_map('strval', $involvementTypesPost)));
$involvementOther = trim($_POST['involvement_other'] ?? '');

$personsOther = trim($_POST['involved_personnel_other'] ?? '');
$rawUserIds = $_POST['involved_personnel_user_ids'] ?? [];
if (!is_array($rawUserIds)) {
    $rawUserIds = [];
}
$involvedUserIds = [];
foreach ($rawUserIds as $tid) {
    $tid = (int) $tid;
    if ($tid > 0) {
        $involvedUserIds[$tid] = true;
    }
}
$involvedUserIds = array_keys($involvedUserIds);

$applicableEndorser = trim($_POST['applicable_endorser'] ?? '');
$supervisorEmail = trim($_POST['supervisor_email'] ?? '');
$endorserVenueAvailability = trim($_POST['endorser_venue_availability'] ?? '');
$endorserElectricity = trim($_POST['endorser_electricity'] ?? '');
$universityFundingRequested = strtolower(trim($_POST['university_funding_requested'] ?? ''));

$supportSel = $_POST['ntarf_support'] ?? [];
if (!is_array($supportSel)) {
    $supportSel = [];
}
$supportSel = array_values(array_unique(array_map('strval', $supportSel)));
$supportOther = trim($_POST['ntarf_support_other'] ?? '');

$publicity = $_POST['publicity'] ?? [];
if (!is_array($publicity)) {
    $publicity = [];
}
$publicity = array_values(array_unique(array_map('strval', $publicity)));
$publicityOther = trim($_POST['publicity_other'] ?? '');

$fundingChargedTo = trim($_POST['funding_charged_to'] ?? '');
$fundingSpecifier = trim($_POST['funding_specifier'] ?? '');
$fundingSpecifierOther = trim($_POST['funding_specifier_other'] ?? '');
$endorserFundAvailability = trim($_POST['endorser_fund_availability'] ?? '');
$totalEstimatedAmount = trim($_POST['total_estimated_amount'] ?? '');

if ($requesterName === '' || $requesterEmail === '' || $activityRequested === '' || $mainOrganizer === '' || $justification === '') {
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
    exit;
}

if (!isset($activityCampusSet[$activityCampus])) {
    echo json_encode(['success' => false, 'message' => 'Select which campus will serve as the venue.']);
    exit;
}
if ($activityCampus === 'OUTSIDE THE CAMPUS' && $activityCampusOther === '') {
    echo json_encode(['success' => false, 'message' => 'Specify the exact off-campus location.']);
    exit;
}

if ($venueSite === '__other__') {
    if ($venueSiteOther === '') {
        echo json_encode(['success' => false, 'message' => 'Specify the venue (Other).']);
        exit;
    }
} elseif (!isset($venueSiteSet[$venueSite])) {
    echo json_encode(['success' => false, 'message' => 'Select a venue from the list.']);
    exit;
}

$involvementTypesFiltered = array_values(array_filter($involvementTypesPost, function ($k) use ($involvementKeySet) {
    return in_array($k, $involvementKeySet, true);
}));
if (count($involvementTypesFiltered) === 0 && $involvementOther === '') {
    echo json_encode(['success' => false, 'message' => 'Select at least one type of involvement or describe other.']);
    exit;
}

if (!isset($endorserVenueSet[$endorserVenueAvailability])) {
    echo json_encode(['success' => false, 'message' => 'Select endorser for venue availability.']);
    exit;
}
if (!isset($endorserElectricitySet[$endorserElectricity])) {
    echo json_encode(['success' => false, 'message' => 'Select endorser for electricity and generator use.']);
    exit;
}

if ($universityFundingRequested !== 'yes' && $universityFundingRequested !== 'no') {
    echo json_encode(['success' => false, 'message' => 'Indicate whether university funding is being requested (Yes or No).']);
    exit;
}

$personLines = [];
if (!empty($involvedUserIds)) {
    $placeholders = implode(',', array_fill(0, count($involvedUserIds), '?'));
    $stTravel = $db->prepare(
        "SELECT fp.user_id, fp.designation, fp.position, u.first_name, u.last_name, u.user_type
         FROM faculty_profiles fp
         INNER JOIN users u ON u.id = fp.user_id
         WHERE fp.user_id IN ($placeholders) AND u.user_type IN ('faculty', 'staff') AND u.is_active = 1"
    );
    $stTravel->execute($involvedUserIds);
    $foundTravel = [];
    while ($tr = $stTravel->fetch(PDO::FETCH_ASSOC)) {
        $foundTravel[(int) $tr['user_id']] = $tr;
    }
    foreach ($involvedUserIds as $tuid) {
        if (!isset($foundTravel[$tuid])) {
            echo json_encode(['success' => false, 'message' => 'One or more selected personnel are invalid. Reload the form and try again.']);
            exit;
        }
        $personLines[] = tarf_travel_person_display_line($foundTravel[$tuid]);
    }
}
if ($personsOther !== '') {
    $personLines[] = $personsOther;
}
$involvedPersonnelText = implode("\n", $personLines);
if (trim($involvedPersonnelText) === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Select at least one person from the directory, or enter names under Additional involved personnel.',
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

if ($dateActivityStart === '' || $dateActivityEnd === '') {
    echo json_encode(['success' => false, 'message' => 'Activity start and end dates are required.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateActivityStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateActivityEnd)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit;
}

if ($dateActivityStart > $dateActivityEnd) {
    echo json_encode(['success' => false, 'message' => 'Activity end date must be on or after the start date.']);
    exit;
}

if ($timeActivityStart === '' || $timeActivityEnd === '') {
    echo json_encode(['success' => false, 'message' => 'Activity start and end times are required.']);
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
        'message' => 'The applicable endorser you selected is not linked to a portal user yet. Contact the administrator.',
    ]);
    exit;
}

$supportSel = array_values(array_filter($supportSel, function ($k) use ($supportSet) {
    return in_array($k, $supportSet, true);
}));
if (count($supportSel) === 0 && $supportOther === '') {
    echo json_encode(['success' => false, 'message' => 'Select at least one requested support option or describe other support.']);
    exit;
}

$publicity = array_values(array_filter($publicity, function ($k) use ($allowedPub) {
    return in_array($k, $allowedPub, true);
}));
if (count($publicity) === 0) {
    echo json_encode(['success' => false, 'message' => 'Select at least one publicity / coverage option (or N/A).']);
    exit;
}

if ($totalEstimatedAmount === '') {
    echo json_encode(['success' => false, 'message' => 'Total estimated amount is required (enter 0 if not applicable).']);
    exit;
}

$needsFundingDetail = ($universityFundingRequested === 'yes');

if ($needsFundingDetail) {
    if (!isset($fundChargedSet[$fundingChargedTo])) {
        echo json_encode(['success' => false, 'message' => 'Funding charged to is required when university funding is requested.']);
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
} else {
    $fundingChargedTo = '';
    $fundingSpecifierDisplay = null;
    $endorserFundAvailability = '';
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

$draftFormForVenue = [
    'activity_campus' => $activityCampus,
    'activity_campus_other' => $activityCampusOther,
    'venue_site' => $venueSite,
    'venue_site_other' => $venueSiteOther,
];
$venueDisplay = ntarf_compose_venue_display_line($draftFormForVenue);

$typeOfInvolvementDisplay = ntarf_format_involvement_display([
    'involvement_types' => $involvementTypesFiltered,
    'involvement_other' => $involvementOther,
    'type_of_involvement' => '',
], $opts);

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
    if (!empty($_FILES['lib_file']['name'])) {
        $addFiles($_FILES['lib_file'], 'lib', 5);
    }
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$serialYear = (int) date('Y');

$formData = [
    'form_kind' => 'ntarf',
    'requester_name' => $requesterName,
    'requester_email' => $requesterEmail,
    'college_office_project' => $collegeDisplay,
    'activity_requested' => $activityRequested,
    'main_organizer' => $mainOrganizer,
    'justification' => $justification,
    'activity_campus' => $activityCampus,
    'activity_campus_other' => $activityCampusOther !== '' ? $activityCampusOther : null,
    'venue_site' => $venueSite,
    'venue_site_other' => $venueSiteOther !== '' ? $venueSiteOther : null,
    'venue' => $venueDisplay,
    'date_activity_start' => $dateActivityStart,
    'date_activity_end' => $dateActivityEnd,
    'time_activity_start' => $timeActivityStart,
    'time_activity_end' => $timeActivityEnd,
    'involved_wpu_personnel' => $involvedPersonnelText,
    'involved_personnel_user_ids' => $involvedUserIds,
    'involved_personnel_other' => $personsOther !== '' ? $personsOther : null,
    'involvement_types' => $involvementTypesFiltered,
    'involvement_other' => $involvementOther !== '' ? $involvementOther : null,
    'type_of_involvement' => $typeOfInvolvementDisplay,
    'publicity' => $publicity,
    'publicity_other' => $publicityOther !== '' ? $publicityOther : null,
    'ntarf_support' => $supportSel,
    'ntarf_support_other' => $supportOther,
    'applicable_endorser' => $applicableEndorser,
    'endorser_venue_availability' => $endorserVenueAvailability,
    'endorser_electricity' => $endorserElectricity,
    'supervisor_email' => $supervisorEmail,
    'university_funding_requested' => $universityFundingRequested,
    'funding_charged_to' => $needsFundingDetail ? $fundingChargedTo : null,
    'funding_specifier' => $needsFundingDetail ? $fundingSpecifierDisplay : null,
    'endorser_fund_availability' => $needsFundingDetail ? $endorserFundAvailability : null,
    'total_estimated_amount' => $totalEstimatedAmount,
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
    'message' => 'Your NTARF was submitted. Your supervisor (pardon opener), applicable endorser, and Budget/Accounting (when applicable) may endorse it in parallel before it goes to the President for final approval.',
    'id' => $newId,
    'view_url' => $viewUrl,
]);
