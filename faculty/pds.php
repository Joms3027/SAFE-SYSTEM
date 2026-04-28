<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/notifications.php';

// Defensive save: if this is a POST with PDS data but the session was lost
// (e.g. due to session file race condition), persist the data before redirecting.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['last_name']) && !isLoggedIn()) {
    try {
        $database = Database::getInstance();
        $dbEmergency = $database->getConnection();
        // Try to identify the user from the POST email to save their draft
        $emailForLookup = trim($_POST['email'] ?? '');
        if ($emailForLookup) {
            $lookupStmt = $dbEmergency->prepare("SELECT id FROM users WHERE email = ? AND user_type IN ('faculty','staff') AND is_active = 1 LIMIT 1");
            $lookupStmt->execute([$emailForLookup]);
            $lookupUser = $lookupStmt->fetch(PDO::FETCH_ASSOC);
            if ($lookupUser) {
                $emergencyFacultyId = $lookupUser['id'];
                $existingStmt = $dbEmergency->prepare("SELECT id FROM faculty_pds WHERE faculty_id = ? AND status IN ('draft','rejected') ORDER BY created_at DESC LIMIT 1");
                $existingStmt->execute([$emergencyFacultyId]);
                $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
                $emergencyData = [
                    'faculty_id' => $emergencyFacultyId,
                    'last_name' => trim($_POST['last_name'] ?? '') ?: null,
                    'first_name' => trim($_POST['first_name'] ?? '') ?: null,
                    'middle_name' => trim($_POST['middle_name'] ?? '') ?: null,
                    'name_extension' => trim($_POST['name_extension'] ?? '') ?: null,
                    'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
                    'place_of_birth' => trim($_POST['place_of_birth'] ?? '') ?: null,
                    'sex' => !empty($_POST['sex']) ? $_POST['sex'] : null,
                    'civil_status' => !empty($_POST['civil_status']) ? $_POST['civil_status'] : null,
                    'email' => $emailForLookup ?: null,
                    'mobile_no' => trim($_POST['mobile_no'] ?? '') ?: null,
                    'residential_address' => trim($_POST['residential_address'] ?? '') ?: null,
                    'permanent_address' => trim($_POST['permanent_address'] ?? '') ?: null,
                    'spouse_last_name' => trim($_POST['spouse_last_name'] ?? '') ?: null,
                    'spouse_first_name' => trim($_POST['spouse_first_name'] ?? '') ?: null,
                    'father_last_name' => trim($_POST['father_last_name'] ?? '') ?: null,
                    'father_first_name' => trim($_POST['father_first_name'] ?? '') ?: null,
                    'mother_last_name' => trim($_POST['mother_last_name'] ?? '') ?: null,
                    'mother_first_name' => trim($_POST['mother_first_name'] ?? '') ?: null,
                    'children_info' => json_encode($_POST['children'] ?? []),
                    'educational_background' => json_encode($_POST['education'] ?? []),
                    'civil_service_eligibility' => json_encode($_POST['eligibility'] ?? []),
                    'work_experience' => json_encode($_POST['experience'] ?? []),
                    'voluntary_work' => json_encode($_POST['voluntary'] ?? []),
                    'learning_development' => json_encode($_POST['learning'] ?? []),
                    'other_info' => json_encode($_POST['other'] ?? []),
                    'additional_questions' => json_encode($_POST['additional_questions'] ?? []),
                    'position' => trim($_POST['position'] ?? '') ?: null,
                ];
                if ($existingRow) {
                    $fields = []; $vals = [];
                    foreach ($emergencyData as $k => $v) { $fields[] = "`$k` = ?"; $vals[] = $v; }
                    $vals[] = $existingRow['id'];
                    $dbEmergency->prepare("UPDATE faculty_pds SET " . implode(', ', $fields) . " WHERE id = ?")->execute($vals);
                } else {
                    $emergencyData['status'] = 'draft';
                    $keys = array_keys($emergencyData);
                    $ph = str_repeat('?,', count($emergencyData) - 1) . '?';
                    $dbEmergency->prepare("INSERT INTO faculty_pds (`" . implode('`, `', $keys) . "`) VALUES ($ph)")->execute(array_values($emergencyData));
                }
                error_log("PDS defensive save: preserved draft for faculty ID $emergencyFacultyId (session lost during POST)");
            }
        }
    } catch (Throwable $e) {
        error_log("PDS defensive save failed: " . $e->getMessage());
    }
}

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

// Get positions from position_salary table
$allPositions = getAllPositions();

// Get user data from users and faculty_profiles tables for auto-population
$stmt = $db->prepare("
    SELECT u.first_name, u.last_name, u.middle_name, u.email, 
           fp.employee_id, fp.phone, fp.address, fp.department, fp.position, fp.employment_status, fp.hire_date
    FROM users u 
    LEFT JOIN faculty_profiles fp ON u.id = fp.user_id 
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Get existing PDS data — prioritize submitted/approved over draft so employee sees approval status
$stmt = $db->prepare("
    SELECT * FROM faculty_pds 
    WHERE faculty_id = ? 
    ORDER BY 
        CASE WHEN status IN ('submitted','approved') THEN 0 ELSE 1 END,
        COALESCE(submitted_at, created_at) DESC,
        id DESC 
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$pds = $stmt->fetch(PDO::FETCH_ASSOC);

// Auto-populate PDS fields from user data if PDS doesn't have them
if ($pds) {
    // If PDS exists but fields are empty, use user data as fallback
    if (empty($pds['last_name']) && !empty($userData['last_name'])) {
        $pds['last_name'] = $userData['last_name'];
    }
    if (empty($pds['first_name']) && !empty($userData['first_name'])) {
        $pds['first_name'] = $userData['first_name'];
    }
    if (empty($pds['middle_name']) && !empty($userData['middle_name'])) {
        $pds['middle_name'] = $userData['middle_name'];
    }
    if (empty($pds['agency_employee_no']) && !empty($userData['employee_id'])) {
        $pds['agency_employee_no'] = $userData['employee_id'];
    }
    // Auto-populate contact information from profile
    if (empty($pds['email']) && !empty($userData['email'])) {
        $pds['email'] = $userData['email'];
    }
    if (empty($pds['mobile_no']) && !empty($userData['phone'])) {
        $pds['mobile_no'] = $userData['phone'];
    }
    if (empty($pds['residential_address']) && !empty($userData['address'])) {
        $pds['residential_address'] = $userData['address'];
    }
    if (empty($pds['position']) && !empty($userData['position'])) {
        $pds['position'] = $userData['position'];
    }
} else {
    // If PDS doesn't exist, initialize with user data for form display
    $pds = [];
    $pds['last_name'] = $userData['last_name'] ?? '';
    $pds['first_name'] = $userData['first_name'] ?? '';
    $pds['middle_name'] = $userData['middle_name'] ?? '';
    $pds['agency_employee_no'] = $userData['employee_id'] ?? '';
    $pds['email'] = $userData['email'] ?? '';
    $pds['mobile_no'] = $userData['phone'] ?? '';
    $pds['residential_address'] = $userData['address'] ?? '';
    $pds['position'] = $userData['position'] ?? '';
    $pds['status'] = 'draft'; // Default status for new PDS
    $pds['submitted_at'] = null; // No submission date for new PDS
    $pds['admin_notes'] = null; // No admin notes for new PDS
}

// Prepare other-info container and load normalized references so the form can prepopulate them
$other = [];
if ($pds && isset($pds['id'])) {
    $other = json_decode($pds['other_info'] ?? '{}', true) ?: [];
    
    // Extract additional fields from other_info and add them to $pds for form access
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
        if (isset($other[$field])) {
            $pds[$field] = $other[$field];
        }
    }
    // agency_employee_id: use column if present, else fallback to other_info (pre-migration)
    if (empty($pds['agency_employee_id']) && isset($other['agency_employee_id'])) {
        $pds['agency_employee_id'] = $other['agency_employee_id'];
    }
    
    // Load normalized references stored in pds_references
    try {
        $refStmt = $db->prepare("SELECT id, name, address, phone FROM pds_references WHERE pds_id = ? ORDER BY id");
        $refStmt->execute([$pds['id']]);
        $refs = $refStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($refs)) {
            $other['references'] = $refs;
        }
    } catch (Exception $e) {
        // ignore DB errors here; form will show empty references
    }

    // Load civil service eligibility either from normalized table (if exists) or from JSON column
    try {
        $csStmt = $db->prepare("SELECT id, title, rating, date_of_exam, place_of_exam, license_number, date_of_validity FROM faculty_civil_service_eligibility WHERE pds_id = ? ORDER BY id");
        $csStmt->execute([$pds['id']]);
        $csRows = $csStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($csRows)) {
            $pds['civil_service_eligibility_rows'] = $csRows;
        } else {
            $pds['civil_service_eligibility_rows'] = json_decode($pds['civil_service_eligibility'] ?? '[]', true) ?: [];
        }
    } catch (Exception $e) {
        // If the table does not exist or error occurs, fallback to JSON column
        $pds['civil_service_eligibility_rows'] = json_decode($pds['civil_service_eligibility'] ?? '[]', true) ?: [];
    }
}

$action = $_GET['action'] ?? 'view';
$message = '';
$basePath = getBasePath();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detect if PHP truncated POST data due to max_input_vars limit
    $maxVars = (int) ini_get('max_input_vars');
    $postCount = count($_POST, COUNT_RECURSIVE);
    if ($maxVars > 0 && $postCount >= $maxVars - 10) {
        error_log("WARNING: PDS POST variable count ($postCount) is near or at max_input_vars ($maxVars). Data may be truncated. Increase max_input_vars in php.ini.");
        $_SESSION['error'] = "Your form has too many fields ($postCount) and some data may have been lost. Please contact the administrator to increase the server's max_input_vars setting (currently $maxVars).";
        header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
        exit();
    }

    $action = $_POST['action'] ?? 'save';
    
    // Validate CSRF token
    $csrfValid = validateFormToken($_POST['csrf_token'] ?? '');
    
    if (!$csrfValid) {
        // For draft saves: the user IS authenticated (requireFaculty passed above),
        // so a CSRF failure is almost certainly a token timing issue (session
        // regeneration, auto-save token refresh, expired token) -- not an attack.
        // Let the full save logic run so no data is lost.
        if ($action === 'save') {
            error_log("CSRF validation failed for PDS draft save (user {$_SESSION['user_id']}) - proceeding with save (user is authenticated)");
        } else {
            // For submit/other actions, enforce CSRF strictly
            if ($action === 'submit') {
                $checkStmt = $db->prepare("SELECT id, status, submitted_at FROM faculty_pds WHERE faculty_id = ? AND status IN ('submitted','approved') ORDER BY submitted_at DESC LIMIT 1");
                $checkStmt->execute([$_SESSION['user_id']]);
                $recentPds = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($recentPds && !empty($recentPds['submitted_at'])) {
                    $submittedAt = strtotime($recentPds['submitted_at']);
                    if ($submittedAt && (time() - $submittedAt) <= 120) {
                        $_SESSION['success'] = 'PDS submitted successfully for review! Your PDS has been sent to the admin and cannot be edited until reviewed.';
                        header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
                        exit();
                    }
                }
            }

            $_SESSION['error'] = "Invalid form submission. Please try again.";
            header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
            exit();
        }
    }
    
    if ($action === 'save') {
        // Save PDS data
        $additionalQuestions = [
            'related_authority_third' => sanitizeInput($_POST['additional_questions']['related_authority_third'] ?? ''),
            'related_authority_fourth' => sanitizeInput($_POST['additional_questions']['related_authority_fourth'] ?? ''),
            'related_authority_details' => sanitizeInput($_POST['additional_questions']['related_authority_details'] ?? ''),
            'found_guilty_admin' => sanitizeInput($_POST['additional_questions']['found_guilty_admin'] ?? ''),
            'criminally_charged' => sanitizeInput($_POST['additional_questions']['criminally_charged'] ?? ''),
            'criminal_charge_date' => sanitizeInput($_POST['additional_questions']['criminal_charge_date'] ?? ''),
            'criminal_charge_status' => sanitizeInput($_POST['additional_questions']['criminal_charge_status'] ?? ''),
            'convicted_crime' => sanitizeInput($_POST['additional_questions']['convicted_crime'] ?? ''),
            'separated_service' => sanitizeInput($_POST['additional_questions']['separated_service'] ?? ''),
            'candidate_election' => sanitizeInput($_POST['additional_questions']['candidate_election'] ?? ''),
            'resigned_for_election' => sanitizeInput($_POST['additional_questions']['resigned_for_election'] ?? ''),
            'immigrant_status' => sanitizeInput($_POST['additional_questions']['immigrant_status'] ?? ''),
            'indigenous_group' => sanitizeInput($_POST['additional_questions']['indigenous_group'] ?? ''),
            'person_with_disability' => sanitizeInput($_POST['additional_questions']['person_with_disability'] ?? ''),
            'solo_parent' => sanitizeInput($_POST['additional_questions']['solo_parent'] ?? ''),
        ];
        // Build residential address from components or use full address field
        $residentialAddressParts = [];
        if (!empty($_POST['residential_house_no'])) $residentialAddressParts[] = $_POST['residential_house_no'];
        if (!empty($_POST['residential_street'])) $residentialAddressParts[] = $_POST['residential_street'];
        if (!empty($_POST['residential_subdivision'])) $residentialAddressParts[] = $_POST['residential_subdivision'];
        if (!empty($_POST['residential_barangay'])) $residentialAddressParts[] = $_POST['residential_barangay'];
        if (!empty($_POST['residential_city'])) $residentialAddressParts[] = $_POST['residential_city'];
        if (!empty($_POST['residential_province'])) $residentialAddressParts[] = $_POST['residential_province'];
        $residentialAddress = !empty($_POST['residential_address']) 
            ? sanitizeInput($_POST['residential_address']) 
            : (!empty($residentialAddressParts) ? sanitizeInput(implode(', ', $residentialAddressParts)) : '');
        
        // Build permanent address from components or use full address field
        $permanentAddressParts = [];
        if (!empty($_POST['permanent_house_no'])) $permanentAddressParts[] = $_POST['permanent_house_no'];
        if (!empty($_POST['permanent_street'])) $permanentAddressParts[] = $_POST['permanent_street'];
        if (!empty($_POST['permanent_subdivision'])) $permanentAddressParts[] = $_POST['permanent_subdivision'];
        if (!empty($_POST['permanent_barangay'])) $permanentAddressParts[] = $_POST['permanent_barangay'];
        if (!empty($_POST['permanent_city'])) $permanentAddressParts[] = $_POST['permanent_city'];
        if (!empty($_POST['permanent_province'])) $permanentAddressParts[] = $_POST['permanent_province'];
        $permanentAddress = !empty($_POST['permanent_address']) 
            ? sanitizeInput($_POST['permanent_address']) 
            : (!empty($permanentAddressParts) ? sanitizeInput(implode(', ', $permanentAddressParts)) : '');
        
        // Prepare other_info with additional fields not in main table
        $otherInfo = $_POST['other'] ?? [];
        $otherInfo['dual_citizenship_country'] = sanitizeInput($_POST['dual_citizenship_country'] ?? '');
        $otherInfo['umid_id'] = sanitizeInput($_POST['umid_id'] ?? '');
        $otherInfo['philsys_number'] = sanitizeInput($_POST['philsys_number'] ?? '');
        $otherInfo['residential_house_no'] = sanitizeInput($_POST['residential_house_no'] ?? '');
        $otherInfo['residential_street'] = sanitizeInput($_POST['residential_street'] ?? '');
        $otherInfo['residential_subdivision'] = sanitizeInput($_POST['residential_subdivision'] ?? '');
        $otherInfo['residential_barangay'] = sanitizeInput($_POST['residential_barangay'] ?? '');
        $otherInfo['residential_city'] = sanitizeInput($_POST['residential_city'] ?? '');
        $otherInfo['residential_province'] = sanitizeInput($_POST['residential_province'] ?? '');
        $otherInfo['permanent_house_no'] = sanitizeInput($_POST['permanent_house_no'] ?? '');
        $otherInfo['permanent_street'] = sanitizeInput($_POST['permanent_street'] ?? '');
        $otherInfo['permanent_subdivision'] = sanitizeInput($_POST['permanent_subdivision'] ?? '');
        $otherInfo['permanent_barangay'] = sanitizeInput($_POST['permanent_barangay'] ?? '');
        $otherInfo['permanent_city'] = sanitizeInput($_POST['permanent_city'] ?? '');
        $otherInfo['permanent_province'] = sanitizeInput($_POST['permanent_province'] ?? '');
        $otherInfo['spouse_name_extension'] = sanitizeInput($_POST['spouse_name_extension'] ?? '');
        $otherInfo['sworn_date'] = sanitizeInput($_POST['sworn_date'] ?? '');
        $otherInfo['government_id_number'] = sanitizeInput($_POST['government_id_number'] ?? '');
        $otherInfo['government_id_issue_date'] = sanitizeInput($_POST['government_id_issue_date'] ?? '');
        $otherInfo['government_id_issue_place'] = sanitizeInput($_POST['government_id_issue_place'] ?? '');
        
        // Convert height and weight to proper numeric types
        $height = !empty($_POST['height']) ? (is_numeric($_POST['height']) ? floatval($_POST['height']) : null) : null;
        $weight = !empty($_POST['weight']) ? (is_numeric($_POST['weight']) ? floatval($_POST['weight']) : null) : null;
        
        // For draft saves, allow saving even with empty fields - users can fill them later
        $lastName = trim(sanitizeInput($_POST['last_name'] ?? ''));
        $firstName = trim(sanitizeInput($_POST['first_name'] ?? ''));
        // Note: We allow empty names for draft saves to enable partial form completion
        
        // Format date_of_birth and date_accomplished properly
        $dateOfBirth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $dateAccomplished = !empty($_POST['date_accomplished']) ? $_POST['date_accomplished'] : date('Y-m-d');
        // Ensure date_accomplished is in datetime format if needed
        if ($dateAccomplished && strpos($dateAccomplished, ' ') === false) {
            $dateAccomplished = $dateAccomplished . ' 00:00:00';
        }
        
        $pdsData = [
            'faculty_id' => $_SESSION['user_id'],
            'last_name' => $lastName ?: null,
            'first_name' => $firstName ?: null,
            'middle_name' => sanitizeInput($_POST['middle_name'] ?? '') ?: null,
            'name_extension' => sanitizeInput($_POST['name_extension'] ?? '') ?: null,
            'date_of_birth' => $dateOfBirth ?: null,
            'place_of_birth' => sanitizeInput($_POST['place_of_birth'] ?? '') ?: null,
            'sex' => !empty($_POST['sex']) ? $_POST['sex'] : null,
            'civil_status' => !empty($_POST['civil_status']) ? $_POST['civil_status'] : null,
            'height' => $height,
            'weight' => $weight,
            'blood_type' => sanitizeInput($_POST['blood_type'] ?? '') ?: null,
            'citizenship' => sanitizeInput($_POST['citizenship'] ?? 'Filipino'),
            'gsis_id' => sanitizeInput($_POST['gsis_id'] ?? '') ?: null,
            'pagibig_id' => sanitizeInput($_POST['pagibig_id'] ?? '') ?: null,
            'philhealth_id' => sanitizeInput($_POST['philhealth_id'] ?? '') ?: null,
            'sss_id' => sanitizeInput($_POST['sss_id'] ?? '') ?: null,
            'tin' => sanitizeInput($_POST['tin'] ?? '') ?: null,
            'agency_employee_no' => sanitizeInput($_POST['agency_employee_no'] ?? '') ?: null,
            'agency_employee_id' => sanitizeInput($_POST['agency_employee_id'] ?? '') ?: null,
            'residential_address' => $residentialAddress ?: null,
            'residential_zipcode' => sanitizeInput($_POST['residential_zipcode'] ?? '') ?: null,
            'residential_telno' => sanitizeInput($_POST['residential_telno'] ?? '') ?: null,
            'permanent_address' => $permanentAddress ?: null,
            'permanent_zipcode' => sanitizeInput($_POST['permanent_zipcode'] ?? '') ?: null,
            'permanent_telno' => sanitizeInput($_POST['permanent_telno'] ?? '') ?: null,
            'email' => sanitizeInput($_POST['email'] ?? '') ?: null,
            'mobile_no' => sanitizeInput($_POST['mobile_no'] ?? '') ?: null,
            'email_alt' => sanitizeInput($_POST['email_alt'] ?? '') ?: null,
            'mobile_no_alt' => sanitizeInput($_POST['mobile_no_alt'] ?? '') ?: null,
            'spouse_last_name' => sanitizeInput($_POST['spouse_last_name'] ?? '') ?: null,
            'spouse_first_name' => sanitizeInput($_POST['spouse_first_name'] ?? '') ?: null,
            'spouse_middle_name' => sanitizeInput($_POST['spouse_middle_name'] ?? '') ?: null,
            'spouse_occupation' => sanitizeInput($_POST['spouse_occupation'] ?? '') ?: null,
            'spouse_employer' => sanitizeInput($_POST['spouse_employer'] ?? '') ?: null,
            'spouse_business_address' => sanitizeInput($_POST['spouse_business_address'] ?? '') ?: null,
            'spouse_telno' => sanitizeInput($_POST['spouse_telno'] ?? '') ?: null,
            'father_last_name' => sanitizeInput($_POST['father_last_name'] ?? '') ?: null,
            'father_first_name' => sanitizeInput($_POST['father_first_name'] ?? '') ?: null,
            'father_middle_name' => sanitizeInput($_POST['father_middle_name'] ?? '') ?: null,
            'father_name_extension' => sanitizeInput($_POST['father_name_extension'] ?? '') ?: null,
            'mother_last_name' => sanitizeInput($_POST['mother_last_name'] ?? '') ?: null,
            'mother_first_name' => sanitizeInput($_POST['mother_first_name'] ?? '') ?: null,
            'mother_middle_name' => sanitizeInput($_POST['mother_middle_name'] ?? '') ?: null,
            'children_info' => json_encode($_POST['children'] ?? []),
            'educational_background' => json_encode($_POST['education'] ?? []),
            'civil_service_eligibility' => json_encode($_POST['eligibility'] ?? []),
            'work_experience' => json_encode($_POST['experience'] ?? []),
            'voluntary_work' => json_encode($_POST['voluntary'] ?? []),
            'learning_development' => json_encode($_POST['learning'] ?? []),
            'other_info' => json_encode($otherInfo),
            'additional_questions' => json_encode($additionalQuestions),
            'position' => sanitizeInput($_POST['position'] ?? '') ?: null,
            'date_accomplished' => $dateAccomplished,
        ];
        
        try {
            // Use transaction to keep main PDS and child rows consistent
            $db->beginTransaction();

            if (isset($pds['id']) && !empty($pds['id'])) {
                // Guard: only allow saving if PDS is in an editable state
                $currentStatus = $pds['status'] ?? 'draft';
                if ($currentStatus !== 'draft' && $currentStatus !== 'rejected') {
                    $db->rollBack();
                    $_SESSION['error'] = 'Cannot save: PDS has already been submitted or approved. It cannot be edited until reviewed.';
                    header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
                    exit();
                }
                // Update existing PDS (status is not modified — stays as-is)
                $updateFields = [];
                $updateValues = [];
                foreach ($pdsData as $key => $value) {
                    $updateFields[] = "`$key` = ?";
                    $updateValues[] = $value;
                }
                $updateValues[] = $pds['id'];
                $stmt = $db->prepare("UPDATE faculty_pds SET " . implode(', ', $updateFields) . " WHERE id = ?");
                $stmt->execute($updateValues);
                $pds_id = $pds['id'];
            } else {
                // Create new PDS — include draft status for INSERT only
                $pdsData['status'] = 'draft';
                $insertFields = array_keys($pdsData);
                $placeholders = str_repeat('?,', count($pdsData) - 1) . '?';
                $stmt = $db->prepare("INSERT INTO faculty_pds (`" . implode('`, `', $insertFields) . "`) VALUES ($placeholders)");
                $stmt->execute(array_values($pdsData));
                $pds_id = $db->lastInsertId();
            }

            // Replace child rows for normalized tables
            // Children
            $db->prepare("DELETE FROM pds_children WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['children']) && is_array($_POST['children'])) {
                $ins = $db->prepare("INSERT INTO pds_children (pds_id, name, dob) VALUES (?, ?, ?)");
                foreach ($_POST['children'] as $c) {
                    $name = trim($c['name'] ?? '');
                    $dob = !empty($c['dob']) ? $c['dob'] : null;
                    if ($name === '' && $dob === null) continue;
                    $ins->execute([$pds_id, $name, $dob]);
                }
            }

            // Education
            $db->prepare("DELETE FROM pds_education WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['education']) && is_array($_POST['education'])) {
                $ins = $db->prepare("INSERT INTO pds_education (pds_id, level, school, degree, from_date, to_date, units_earned, year_graduated, academic_honors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['education'] as $e) {
                    $level = trim($e['level'] ?? '');
                    $school = trim($e['school'] ?? '');
                    $degree = trim($e['degree'] ?? '');
                    $from_date = trim($e['from_date'] ?? '');
                    $to_date = trim($e['to_date'] ?? '');
                    $units_earned = trim($e['units_earned'] ?? '');
                    $year_graduated = trim($e['year_graduated'] ?? '');
                    $academic_honors = trim($e['academic_honors'] ?? '');
                    if ($level === '' && $school === '' && $degree === '') continue;
                    $ins->execute([$pds_id, $level, $school, $degree, $from_date ?: null, $to_date ?: null, $units_earned ?: null, $year_graduated ?: null, $academic_honors ?: null]);
                }
            }

            // Civil Service Eligibility (normalized table)
            $db->prepare("DELETE FROM faculty_civil_service_eligibility WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['eligibility']) && is_array($_POST['eligibility'])) {
                $ins = $db->prepare("INSERT INTO faculty_civil_service_eligibility (pds_id, title, rating, date_of_exam, place_of_exam, license_number, date_of_validity) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['eligibility'] as $el) {
                    $title = trim($el['title'] ?? '');
                    $rating = trim($el['rating'] ?? '');
                    $date_of_exam = trim($el['date_of_exam'] ?? '');
                    $place_of_exam = trim($el['place_of_exam'] ?? '');
                    $license_number = trim($el['license_number'] ?? '');
                    $date_of_validity = trim($el['date_of_validity'] ?? '');
                    if ($title === '' && $rating === '' && $date_of_exam === '' && $place_of_exam === '' && $license_number === '' && $date_of_validity === '') continue;
                    $ins->execute([$pds_id, $title ?: null, $rating ?: null, $date_of_exam ?: null, $place_of_exam ?: null, $license_number ?: null, $date_of_validity ?: null]);
                }
            }

            // Experience
            $db->prepare("DELETE FROM pds_experience WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['experience']) && is_array($_POST['experience'])) {
                $ins = $db->prepare("INSERT INTO pds_experience (pds_id, dates, position, company, salary, salary_grade, employment_status, appointment_status, gov_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['experience'] as $ex) {
                    $dates = trim($ex['dates'] ?? '');
                    $position = trim($ex['position'] ?? '');
                    $company = trim($ex['company'] ?? '');
                    $salary = trim($ex['salary'] ?? '');
                    $salary_grade = trim($ex['salary_grade'] ?? '');
                    $employment_status = trim($ex['employment_status'] ?? '');
                    $appointment_status = trim($ex['appointment_status'] ?? '');
                    $gov_service = trim($ex['gov_service'] ?? '');
                    if ($dates === '' && $position === '' && $company === '' && $salary === '') continue;
                    $ins->execute([$pds_id, $dates, $position, $company, $salary, $salary_grade ?: null, $employment_status ?: null, $appointment_status ?: null, $gov_service ?: null]);
                }
            }

            // Voluntary
            $db->prepare("DELETE FROM pds_voluntary WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['voluntary']) && is_array($_POST['voluntary'])) {
                $ins = $db->prepare("INSERT INTO pds_voluntary (pds_id, org, dates, hours, position) VALUES (?, ?, ?, ?, ?)");
                foreach ($_POST['voluntary'] as $v) {
                    $org = trim($v['org'] ?? '');
                    $dates = trim($v['dates'] ?? '');
                    $hours = trim($v['hours'] ?? '');
                    $pos = trim($v['position'] ?? '');
                    if ($org === '' && $dates === '') continue;
                    $ins->execute([$pds_id, $org, $dates, $hours ?: null, $pos ?: null]);
                }
            }

            // Learning and Development
            $db->prepare("DELETE FROM pds_learning WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['learning']) && is_array($_POST['learning'])) {
                $ins = $db->prepare("INSERT INTO pds_learning (pds_id, title, dates, hours, type, conducted_by, has_certificate, venue, certificate_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['learning'] as $l) {
                    $title = trim($l['title'] ?? '');
                    $dates = trim($l['dates'] ?? '');
                    $hours = trim($l['hours'] ?? '');
                    $type = trim($l['type'] ?? '');
                    $conducted_by = trim($l['conducted_by'] ?? '');
                    $has_certificate = trim($l['has_certificate'] ?? '');
                    $venue = trim($l['venue'] ?? '');
                    $certificate_details = trim($l['certificate_details'] ?? '');
                    if ($title === '' && $dates === '') continue;
                    $ins->execute([$pds_id, $title, $dates, $hours ?: null, $type ?: null, $conducted_by ?: null, $has_certificate ?: null, $venue ?: null, $certificate_details ?: null]);
                }
            }

            // References (stored under other[references])
            $db->prepare("DELETE FROM pds_references WHERE pds_id = ?")->execute([$pds_id]);
            $refs = $_POST['other']['references'] ?? [];
            if (!empty($refs) && is_array($refs)) {
                $ins = $db->prepare("INSERT INTO pds_references (pds_id, name, address, phone) VALUES (?, ?, ?, ?)");
                foreach ($refs as $r) {
                    $name = trim($r['name'] ?? '');
                    $address = trim($r['address'] ?? '');
                    $phone = trim($r['phone'] ?? '');
                    if ($name === '' && $address === '' && $phone === '') continue;
                    $ins->execute([$pds_id, $name, $address, $phone]);
                }
            }

            $db->commit();

            logAction('PDS_SAVE', 'Saved PDS as draft' . (isset($pds_id) ? " (ID: $pds_id)" : ''));

            // Use PRG: set flash message and redirect to avoid duplicate resubmits on reload
            $_SESSION['success'] = 'PDS saved as draft successfully! You can continue editing and submit when ready.';
            // Refresh will happen after redirect - stay on PDS page
            header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
            exit();

        } catch (Exception $e) {
            $db->rollBack();
            // Log detailed error for debugging
            $errorDetails = 'PDS save error: ' . $e->getMessage();
            if ($e instanceof PDOException) {
                $errorDetails .= ' | SQL State: ' . $e->getCode() . ' | Error Info: ' . json_encode($e->errorInfo ?? []);
            }
            error_log($errorDetails);
            
            // Log to file for easier debugging
            try {
                $logDir = __DIR__ . '/../storage/logs';
                if (!is_dir($logDir)) mkdir($logDir, 0755, true);
                $logFile = $logDir . '/pds_error.log';
                $logEntry = date('Y-m-d H:i:s') . ' - ' . $errorDetails . PHP_EOL;
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            } catch (Exception $logErr) {
                // Ignore logging errors
            }
            
            // PRG: set error and redirect back to form
            // Show actual error in development mode, generic message in production
            $errorMessage = 'An error occurred while saving your PDS. Please try again.';
            if (defined('DEBUG') && DEBUG) {
                $errorMessage .= ' Error: ' . $e->getMessage();
            }
            $_SESSION['error'] = $errorMessage;
            header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
            exit();
        }
        
    } elseif ($action === 'submit') {
        // Submit PDS for review — validate all required (*) fields; decline submission if any are empty
        $requiredForSubmit = [
            'last_name' => 'Last Name',
            'first_name' => 'First Name',
            'date_of_birth' => 'Date of Birth',
            'place_of_birth' => 'Place of Birth',
            'sex' => 'Sex',
            'civil_status' => 'Civil Status',
            'height' => 'Height',
            'weight' => 'Weight',
            'blood_type' => 'Blood Type',
            'citizenship' => 'Citizenship',
            'gsis_id' => 'GSIS ID No.',
            'pagibig_id' => 'Pag-IBIG ID No.',
            'umid_id' => 'UMID ID No.',
            'philhealth_id' => 'PhilHealth No.',
            'philsys_number' => 'PhilSys Number (PSN)',
            'tin' => 'TIN',
            'agency_employee_id' => 'AGENCY EMPLOYEE ID',
            'residential_street' => 'Residential Street',
            'residential_barangay' => 'Residential Barangay',
            'residential_city' => 'Residential City/Municipality',
            'residential_province' => 'Residential Province',
            'residential_zipcode' => 'Residential ZIP Code',
            'email' => 'Email Address',
            'mobile_no' => 'Mobile No.',
            'email_alt' => 'Email Address (Alternative)',
            'mobile_no_alt' => 'Mobile No. (Alternative)',
            'father_last_name' => "Father's Last Name",
            'father_first_name' => "Father's First Name",
            'mother_last_name' => "Mother's Last Name",
            'mother_first_name' => "Mother's First Name",
        ];
        $missing = [];
        foreach ($requiredForSubmit as $field => $label) {
            $val = isset($_POST[$field]) ? trim((string) $_POST[$field]) : '';
            if ($val === '') {
                $missing[] = $label;
            }
        }
        if (!empty($missing)) {
            // Save the form data as draft BEFORE rejecting so the user does not
            // lose all their work just because a few required fields are missing.
            $draftSaved = false;
            try {
                $draftPdsData = [
                    'faculty_id' => $_SESSION['user_id'],
                    'last_name' => trim($_POST['last_name'] ?? '') ?: null,
                    'first_name' => trim($_POST['first_name'] ?? '') ?: null,
                    'middle_name' => trim($_POST['middle_name'] ?? '') ?: null,
                    'name_extension' => trim($_POST['name_extension'] ?? '') ?: null,
                    'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
                    'place_of_birth' => trim($_POST['place_of_birth'] ?? '') ?: null,
                    'sex' => !empty($_POST['sex']) ? $_POST['sex'] : null,
                    'civil_status' => !empty($_POST['civil_status']) ? $_POST['civil_status'] : null,
                    'height' => !empty($_POST['height']) && is_numeric($_POST['height']) ? floatval($_POST['height']) : null,
                    'weight' => !empty($_POST['weight']) && is_numeric($_POST['weight']) ? floatval($_POST['weight']) : null,
                    'blood_type' => trim($_POST['blood_type'] ?? '') ?: null,
                    'citizenship' => trim($_POST['citizenship'] ?? 'Filipino'),
                    'gsis_id' => trim($_POST['gsis_id'] ?? '') ?: null,
                    'pagibig_id' => trim($_POST['pagibig_id'] ?? '') ?: null,
                    'philhealth_id' => trim($_POST['philhealth_id'] ?? '') ?: null,
                    'sss_id' => trim($_POST['sss_id'] ?? '') ?: null,
                    'tin' => trim($_POST['tin'] ?? '') ?: null,
                    'agency_employee_no' => trim($_POST['agency_employee_no'] ?? '') ?: null,
                    'agency_employee_id' => trim($_POST['agency_employee_id'] ?? '') ?: null,
                    'residential_address' => trim($_POST['residential_address'] ?? '') ?: null,
                    'residential_zipcode' => trim($_POST['residential_zipcode'] ?? '') ?: null,
                    'residential_telno' => trim($_POST['residential_telno'] ?? '') ?: null,
                    'permanent_address' => trim($_POST['permanent_address'] ?? '') ?: null,
                    'permanent_zipcode' => trim($_POST['permanent_zipcode'] ?? '') ?: null,
                    'permanent_telno' => trim($_POST['permanent_telno'] ?? '') ?: null,
                    'email' => trim($_POST['email'] ?? '') ?: null,
                    'mobile_no' => trim($_POST['mobile_no'] ?? '') ?: null,
                    'email_alt' => trim($_POST['email_alt'] ?? '') ?: null,
                    'mobile_no_alt' => trim($_POST['mobile_no_alt'] ?? '') ?: null,
                    'spouse_last_name' => trim($_POST['spouse_last_name'] ?? '') ?: null,
                    'spouse_first_name' => trim($_POST['spouse_first_name'] ?? '') ?: null,
                    'spouse_middle_name' => trim($_POST['spouse_middle_name'] ?? '') ?: null,
                    'spouse_occupation' => trim($_POST['spouse_occupation'] ?? '') ?: null,
                    'spouse_employer' => trim($_POST['spouse_employer'] ?? '') ?: null,
                    'spouse_business_address' => trim($_POST['spouse_business_address'] ?? '') ?: null,
                    'spouse_telno' => trim($_POST['spouse_telno'] ?? '') ?: null,
                    'father_last_name' => trim($_POST['father_last_name'] ?? '') ?: null,
                    'father_first_name' => trim($_POST['father_first_name'] ?? '') ?: null,
                    'father_middle_name' => trim($_POST['father_middle_name'] ?? '') ?: null,
                    'father_name_extension' => trim($_POST['father_name_extension'] ?? '') ?: null,
                    'mother_last_name' => trim($_POST['mother_last_name'] ?? '') ?: null,
                    'mother_first_name' => trim($_POST['mother_first_name'] ?? '') ?: null,
                    'mother_middle_name' => trim($_POST['mother_middle_name'] ?? '') ?: null,
                    'children_info' => json_encode($_POST['children'] ?? []),
                    'educational_background' => json_encode($_POST['education'] ?? []),
                    'civil_service_eligibility' => json_encode($_POST['eligibility'] ?? []),
                    'work_experience' => json_encode($_POST['experience'] ?? []),
                    'voluntary_work' => json_encode($_POST['voluntary'] ?? []),
                    'learning_development' => json_encode($_POST['learning'] ?? []),
                    'other_info' => json_encode($_POST['other'] ?? []),
                    'additional_questions' => json_encode($_POST['additional_questions'] ?? []),
                    'position' => trim($_POST['position'] ?? '') ?: null,
                    'date_accomplished' => !empty($_POST['date_accomplished']) ? $_POST['date_accomplished'] . (strpos($_POST['date_accomplished'], ' ') === false ? ' 00:00:00' : '') : null,
                ];

                if (isset($pds['id']) && !empty($pds['id']) && in_array($pds['status'] ?? '', ['draft', 'rejected'])) {
                    $uf = []; $uv = [];
                    foreach ($draftPdsData as $k => $v) { $uf[] = "`$k` = ?"; $uv[] = $v; }
                    $uv[] = $pds['id'];
                    $db->prepare("UPDATE faculty_pds SET " . implode(', ', $uf) . " WHERE id = ?")->execute($uv);
                } else {
                    $draftPdsData['status'] = 'draft';
                    $ik = array_keys($draftPdsData);
                    $ph = str_repeat('?,', count($draftPdsData) - 1) . '?';
                    $db->prepare("INSERT INTO faculty_pds (`" . implode('`, `', $ik) . "`) VALUES ($ph)")->execute(array_values($draftPdsData));
                }
                $draftSaved = true;
            } catch (Exception $draftErr) {
                error_log('Failed to auto-save draft before submit rejection: ' . $draftErr->getMessage());
            }

            $savedNote = $draftSaved ? ' Your progress has been saved as a draft.' : '';
            $_SESSION['error'] = 'Cannot submit PDS: the following required fields (*) must be filled: ' . implode(', ', $missing) . '.' . $savedNote . ' Please complete the missing fields and try again.';
            header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
            exit();
        }

        // IMPORTANT: First save all form data to ensure any changes are persisted before submission
        // This ensures that if user makes changes and clicks Submit without clicking Save as Draft first,
        // those changes are still saved.
        
        // Prepare all the same data as the save action
        $additionalQuestions = [
            'related_authority_third' => sanitizeInput($_POST['additional_questions']['related_authority_third'] ?? ''),
            'related_authority_fourth' => sanitizeInput($_POST['additional_questions']['related_authority_fourth'] ?? ''),
            'related_authority_details' => sanitizeInput($_POST['additional_questions']['related_authority_details'] ?? ''),
            'found_guilty_admin' => sanitizeInput($_POST['additional_questions']['found_guilty_admin'] ?? ''),
            'criminally_charged' => sanitizeInput($_POST['additional_questions']['criminally_charged'] ?? ''),
            'criminal_charge_date' => sanitizeInput($_POST['additional_questions']['criminal_charge_date'] ?? ''),
            'criminal_charge_status' => sanitizeInput($_POST['additional_questions']['criminal_charge_status'] ?? ''),
            'convicted_crime' => sanitizeInput($_POST['additional_questions']['convicted_crime'] ?? ''),
            'separated_service' => sanitizeInput($_POST['additional_questions']['separated_service'] ?? ''),
            'candidate_election' => sanitizeInput($_POST['additional_questions']['candidate_election'] ?? ''),
            'resigned_for_election' => sanitizeInput($_POST['additional_questions']['resigned_for_election'] ?? ''),
            'immigrant_status' => sanitizeInput($_POST['additional_questions']['immigrant_status'] ?? ''),
            'indigenous_group' => sanitizeInput($_POST['additional_questions']['indigenous_group'] ?? ''),
            'person_with_disability' => sanitizeInput($_POST['additional_questions']['person_with_disability'] ?? ''),
            'solo_parent' => sanitizeInput($_POST['additional_questions']['solo_parent'] ?? ''),
        ];
        
        // Build residential address from components or use full address field
        $residentialAddressParts = [];
        if (!empty($_POST['residential_house_no'])) $residentialAddressParts[] = $_POST['residential_house_no'];
        if (!empty($_POST['residential_street'])) $residentialAddressParts[] = $_POST['residential_street'];
        if (!empty($_POST['residential_subdivision'])) $residentialAddressParts[] = $_POST['residential_subdivision'];
        if (!empty($_POST['residential_barangay'])) $residentialAddressParts[] = $_POST['residential_barangay'];
        if (!empty($_POST['residential_city'])) $residentialAddressParts[] = $_POST['residential_city'];
        if (!empty($_POST['residential_province'])) $residentialAddressParts[] = $_POST['residential_province'];
        $residentialAddress = !empty($_POST['residential_address']) 
            ? sanitizeInput($_POST['residential_address']) 
            : (!empty($residentialAddressParts) ? sanitizeInput(implode(', ', $residentialAddressParts)) : '');
        
        // Build permanent address from components or use full address field
        $permanentAddressParts = [];
        if (!empty($_POST['permanent_house_no'])) $permanentAddressParts[] = $_POST['permanent_house_no'];
        if (!empty($_POST['permanent_street'])) $permanentAddressParts[] = $_POST['permanent_street'];
        if (!empty($_POST['permanent_subdivision'])) $permanentAddressParts[] = $_POST['permanent_subdivision'];
        if (!empty($_POST['permanent_barangay'])) $permanentAddressParts[] = $_POST['permanent_barangay'];
        if (!empty($_POST['permanent_city'])) $permanentAddressParts[] = $_POST['permanent_city'];
        if (!empty($_POST['permanent_province'])) $permanentAddressParts[] = $_POST['permanent_province'];
        $permanentAddress = !empty($_POST['permanent_address']) 
            ? sanitizeInput($_POST['permanent_address']) 
            : (!empty($permanentAddressParts) ? sanitizeInput(implode(', ', $permanentAddressParts)) : '');
        
        // Prepare other_info with additional fields not in main table
        $otherInfo = $_POST['other'] ?? [];
        $otherInfo['dual_citizenship_country'] = sanitizeInput($_POST['dual_citizenship_country'] ?? '');
        $otherInfo['umid_id'] = sanitizeInput($_POST['umid_id'] ?? '');
        $otherInfo['philsys_number'] = sanitizeInput($_POST['philsys_number'] ?? '');
        $otherInfo['residential_house_no'] = sanitizeInput($_POST['residential_house_no'] ?? '');
        $otherInfo['residential_street'] = sanitizeInput($_POST['residential_street'] ?? '');
        $otherInfo['residential_subdivision'] = sanitizeInput($_POST['residential_subdivision'] ?? '');
        $otherInfo['residential_barangay'] = sanitizeInput($_POST['residential_barangay'] ?? '');
        $otherInfo['residential_city'] = sanitizeInput($_POST['residential_city'] ?? '');
        $otherInfo['residential_province'] = sanitizeInput($_POST['residential_province'] ?? '');
        $otherInfo['permanent_house_no'] = sanitizeInput($_POST['permanent_house_no'] ?? '');
        $otherInfo['permanent_street'] = sanitizeInput($_POST['permanent_street'] ?? '');
        $otherInfo['permanent_subdivision'] = sanitizeInput($_POST['permanent_subdivision'] ?? '');
        $otherInfo['permanent_barangay'] = sanitizeInput($_POST['permanent_barangay'] ?? '');
        $otherInfo['permanent_city'] = sanitizeInput($_POST['permanent_city'] ?? '');
        $otherInfo['permanent_province'] = sanitizeInput($_POST['permanent_province'] ?? '');
        $otherInfo['spouse_name_extension'] = sanitizeInput($_POST['spouse_name_extension'] ?? '');
        $otherInfo['sworn_date'] = sanitizeInput($_POST['sworn_date'] ?? '');
        $otherInfo['government_id_number'] = sanitizeInput($_POST['government_id_number'] ?? '');
        $otherInfo['government_id_issue_date'] = sanitizeInput($_POST['government_id_issue_date'] ?? '');
        $otherInfo['government_id_issue_place'] = sanitizeInput($_POST['government_id_issue_place'] ?? '');
        
        // Convert height and weight to proper numeric types
        $height = !empty($_POST['height']) ? (is_numeric($_POST['height']) ? floatval($_POST['height']) : null) : null;
        $weight = !empty($_POST['weight']) ? (is_numeric($_POST['weight']) ? floatval($_POST['weight']) : null) : null;
        
        // Required fields already validated at start of submit block
        $lastName = trim(sanitizeInput($_POST['last_name'] ?? ''));
        $firstName = trim(sanitizeInput($_POST['first_name'] ?? ''));
        
        // Format date_of_birth and date_accomplished properly
        $dateOfBirth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $dateAccomplished = !empty($_POST['date_accomplished']) ? $_POST['date_accomplished'] : date('Y-m-d');
        if ($dateAccomplished && strpos($dateAccomplished, ' ') === false) {
            $dateAccomplished = $dateAccomplished . ' 00:00:00';
        }
        
        $pdsData = [
            'faculty_id' => $_SESSION['user_id'],
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => sanitizeInput($_POST['middle_name'] ?? '') ?: null,
            'name_extension' => sanitizeInput($_POST['name_extension'] ?? '') ?: null,
            'date_of_birth' => $dateOfBirth ?: null,
            'place_of_birth' => sanitizeInput($_POST['place_of_birth'] ?? '') ?: null,
            'sex' => !empty($_POST['sex']) ? $_POST['sex'] : null,
            'civil_status' => !empty($_POST['civil_status']) ? $_POST['civil_status'] : null,
            'height' => $height,
            'weight' => $weight,
            'blood_type' => sanitizeInput($_POST['blood_type'] ?? '') ?: null,
            'citizenship' => sanitizeInput($_POST['citizenship'] ?? 'Filipino'),
            'gsis_id' => sanitizeInput($_POST['gsis_id'] ?? '') ?: null,
            'pagibig_id' => sanitizeInput($_POST['pagibig_id'] ?? '') ?: null,
            'philhealth_id' => sanitizeInput($_POST['philhealth_id'] ?? '') ?: null,
            'sss_id' => sanitizeInput($_POST['sss_id'] ?? '') ?: null,
            'tin' => sanitizeInput($_POST['tin'] ?? '') ?: null,
            'agency_employee_no' => sanitizeInput($_POST['agency_employee_no'] ?? '') ?: null,
            'agency_employee_id' => sanitizeInput($_POST['agency_employee_id'] ?? '') ?: null,
            'residential_address' => $residentialAddress ?: null,
            'residential_zipcode' => sanitizeInput($_POST['residential_zipcode'] ?? '') ?: null,
            'residential_telno' => sanitizeInput($_POST['residential_telno'] ?? '') ?: null,
            'permanent_address' => $permanentAddress ?: null,
            'permanent_zipcode' => sanitizeInput($_POST['permanent_zipcode'] ?? '') ?: null,
            'permanent_telno' => sanitizeInput($_POST['permanent_telno'] ?? '') ?: null,
            'email' => sanitizeInput($_POST['email'] ?? '') ?: null,
            'mobile_no' => sanitizeInput($_POST['mobile_no'] ?? '') ?: null,
            'email_alt' => sanitizeInput($_POST['email_alt'] ?? '') ?: null,
            'mobile_no_alt' => sanitizeInput($_POST['mobile_no_alt'] ?? '') ?: null,
            'spouse_last_name' => sanitizeInput($_POST['spouse_last_name'] ?? '') ?: null,
            'spouse_first_name' => sanitizeInput($_POST['spouse_first_name'] ?? '') ?: null,
            'spouse_middle_name' => sanitizeInput($_POST['spouse_middle_name'] ?? '') ?: null,
            'spouse_occupation' => sanitizeInput($_POST['spouse_occupation'] ?? '') ?: null,
            'spouse_employer' => sanitizeInput($_POST['spouse_employer'] ?? '') ?: null,
            'spouse_business_address' => sanitizeInput($_POST['spouse_business_address'] ?? '') ?: null,
            'spouse_telno' => sanitizeInput($_POST['spouse_telno'] ?? '') ?: null,
            'father_last_name' => sanitizeInput($_POST['father_last_name'] ?? '') ?: null,
            'father_first_name' => sanitizeInput($_POST['father_first_name'] ?? '') ?: null,
            'father_middle_name' => sanitizeInput($_POST['father_middle_name'] ?? '') ?: null,
            'father_name_extension' => sanitizeInput($_POST['father_name_extension'] ?? '') ?: null,
            'mother_last_name' => sanitizeInput($_POST['mother_last_name'] ?? '') ?: null,
            'mother_first_name' => sanitizeInput($_POST['mother_first_name'] ?? '') ?: null,
            'mother_middle_name' => sanitizeInput($_POST['mother_middle_name'] ?? '') ?: null,
            'children_info' => json_encode($_POST['children'] ?? []),
            'educational_background' => json_encode($_POST['education'] ?? []),
            'civil_service_eligibility' => json_encode($_POST['eligibility'] ?? []),
            'work_experience' => json_encode($_POST['experience'] ?? []),
            'voluntary_work' => json_encode($_POST['voluntary'] ?? []),
            'learning_development' => json_encode($_POST['learning'] ?? []),
            'other_info' => json_encode($otherInfo),
            'additional_questions' => json_encode($additionalQuestions),
            'position' => sanitizeInput($_POST['position'] ?? '') ?: null,
            'date_accomplished' => $dateAccomplished,
        ];
        
        try {
            // Use transaction to keep main PDS and child rows consistent
            $db->beginTransaction();
            
            // When resubmitting: if faculty already has a submitted/approved PDS, replace it
            // instead of creating a new one. Only one PDS per employee should be visible in admin.
            $existingSubmittedStmt = $db->prepare("
                SELECT id FROM faculty_pds 
                WHERE faculty_id = ? AND status IN ('submitted','approved') 
                ORDER BY submitted_at DESC, id DESC LIMIT 1
            ");
            $existingSubmittedStmt->execute([$_SESSION['user_id']]);
            $existingSubmitted = $existingSubmittedStmt->fetch(PDO::FETCH_ASSOC);
            
            $pds_id = null;
            $draftIdToDelete = null;
            
            if ($existingSubmitted) {
                // Replace existing submitted/approved PDS with new data
                $pds_id = (int) $existingSubmitted['id'];
                if (isset($pds['id']) && !empty($pds['id']) && (int) $pds['id'] !== $pds_id) {
                    $draftIdToDelete = (int) $pds['id']; // Will delete orphan draft after update
                }
            } elseif (isset($pds['id']) && !empty($pds['id'])) {
                $currentStatus = $pds['status'] ?? 'draft';
                if ($currentStatus !== 'draft' && $currentStatus !== 'rejected') {
                    $db->rollBack();
                    $_SESSION['error'] = 'PDS has already been submitted or reviewed and cannot be modified.';
                    header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
                    exit();
                }
                $pds_id = (int) $pds['id'];
            }
            
            // First, save/update the PDS data (same logic as save action)
            // Status is NOT included here — it will be set to 'submitted' atomically below
            if ($pds_id !== null) {
                // Update existing PDS (either replacing submitted/approved or updating draft/rejected)
                $updateFields = [];
                $updateValues = [];
                foreach ($pdsData as $key => $value) {
                    $updateFields[] = "`$key` = ?";
                    $updateValues[] = $value;
                }
                if ($existingSubmitted) {
                    $updateFields[] = "admin_notes = NULL";
                    $updateFields[] = "reviewed_by = NULL";
                    $updateFields[] = "reviewed_at = NULL";
                }
                $updateValues[] = $pds_id;
                $stmt = $db->prepare("UPDATE faculty_pds SET " . implode(', ', $updateFields) . " WHERE id = ?");
                $stmt->execute($updateValues);
            } else {
                // Create new PDS — include draft status for INSERT only
                $pdsData['status'] = 'draft';
                $insertFields = array_keys($pdsData);
                $placeholders = str_repeat('?,', count($pdsData) - 1) . '?';
                $stmt = $db->prepare("INSERT INTO faculty_pds (`" . implode('`, `', $insertFields) . "`) VALUES ($placeholders)");
                $stmt->execute(array_values($pdsData));
                $pds_id = (int) $db->lastInsertId();
            }
            
            // Replace child rows for normalized tables (same as save action)
            // Children
            $db->prepare("DELETE FROM pds_children WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['children']) && is_array($_POST['children'])) {
                $ins = $db->prepare("INSERT INTO pds_children (pds_id, name, dob) VALUES (?, ?, ?)");
                foreach ($_POST['children'] as $c) {
                    $name = trim($c['name'] ?? '');
                    $dob = !empty($c['dob']) ? $c['dob'] : null;
                    if ($name === '' && $dob === null) continue;
                    $ins->execute([$pds_id, $name, $dob]);
                }
            }
            
            // Education
            $db->prepare("DELETE FROM pds_education WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['education']) && is_array($_POST['education'])) {
                $ins = $db->prepare("INSERT INTO pds_education (pds_id, level, school, degree, from_date, to_date, units_earned, year_graduated, academic_honors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['education'] as $e) {
                    $level = trim($e['level'] ?? '');
                    $school = trim($e['school'] ?? '');
                    $degree = trim($e['degree'] ?? '');
                    $from_date = trim($e['from_date'] ?? '');
                    $to_date = trim($e['to_date'] ?? '');
                    $units_earned = trim($e['units_earned'] ?? '');
                    $year_graduated = trim($e['year_graduated'] ?? '');
                    $academic_honors = trim($e['academic_honors'] ?? '');
                    if ($level === '' && $school === '' && $degree === '') continue;
                    $ins->execute([$pds_id, $level, $school, $degree, $from_date ?: null, $to_date ?: null, $units_earned ?: null, $year_graduated ?: null, $academic_honors ?: null]);
                }
            }
            
            // Civil Service Eligibility
            $db->prepare("DELETE FROM faculty_civil_service_eligibility WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['eligibility']) && is_array($_POST['eligibility'])) {
                $ins = $db->prepare("INSERT INTO faculty_civil_service_eligibility (pds_id, title, rating, date_of_exam, place_of_exam, license_number, date_of_validity) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['eligibility'] as $el) {
                    $title = trim($el['title'] ?? '');
                    $rating = trim($el['rating'] ?? '');
                    $date_of_exam = trim($el['date_of_exam'] ?? '');
                    $place_of_exam = trim($el['place_of_exam'] ?? '');
                    $license_number = trim($el['license_number'] ?? '');
                    $date_of_validity = trim($el['date_of_validity'] ?? '');
                    if ($title === '' && $rating === '' && $date_of_exam === '' && $place_of_exam === '' && $license_number === '' && $date_of_validity === '') continue;
                    $ins->execute([$pds_id, $title ?: null, $rating ?: null, $date_of_exam ?: null, $place_of_exam ?: null, $license_number ?: null, $date_of_validity ?: null]);
                }
            }
            
            // Experience
            $db->prepare("DELETE FROM pds_experience WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['experience']) && is_array($_POST['experience'])) {
                $ins = $db->prepare("INSERT INTO pds_experience (pds_id, dates, position, company, salary, salary_grade, employment_status, appointment_status, gov_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['experience'] as $ex) {
                    $dates = trim($ex['dates'] ?? '');
                    $position = trim($ex['position'] ?? '');
                    $company = trim($ex['company'] ?? '');
                    $salary = trim($ex['salary'] ?? '');
                    $salary_grade = trim($ex['salary_grade'] ?? '');
                    $employment_status = trim($ex['employment_status'] ?? '');
                    $appointment_status = trim($ex['appointment_status'] ?? '');
                    $gov_service = trim($ex['gov_service'] ?? '');
                    if ($dates === '' && $position === '' && $company === '' && $salary === '') continue;
                    $ins->execute([$pds_id, $dates, $position, $company, $salary, $salary_grade ?: null, $employment_status ?: null, $appointment_status ?: null, $gov_service ?: null]);
                }
            }
            
            // Voluntary
            $db->prepare("DELETE FROM pds_voluntary WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['voluntary']) && is_array($_POST['voluntary'])) {
                $ins = $db->prepare("INSERT INTO pds_voluntary (pds_id, org, dates, hours, position) VALUES (?, ?, ?, ?, ?)");
                foreach ($_POST['voluntary'] as $v) {
                    $org = trim($v['org'] ?? '');
                    $dates = trim($v['dates'] ?? '');
                    $hours = trim($v['hours'] ?? '');
                    $pos = trim($v['position'] ?? '');
                    if ($org === '' && $dates === '') continue;
                    $ins->execute([$pds_id, $org, $dates, $hours ?: null, $pos ?: null]);
                }
            }
            
            // Learning and Development
            $db->prepare("DELETE FROM pds_learning WHERE pds_id = ?")->execute([$pds_id]);
            if (!empty($_POST['learning']) && is_array($_POST['learning'])) {
                $ins = $db->prepare("INSERT INTO pds_learning (pds_id, title, dates, hours, type, conducted_by, has_certificate, venue, certificate_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['learning'] as $l) {
                    $title = trim($l['title'] ?? '');
                    $dates = trim($l['dates'] ?? '');
                    $hours = trim($l['hours'] ?? '');
                    $type = trim($l['type'] ?? '');
                    $conducted_by = trim($l['conducted_by'] ?? '');
                    $has_certificate = trim($l['has_certificate'] ?? '');
                    $venue = trim($l['venue'] ?? '');
                    $certificate_details = trim($l['certificate_details'] ?? '');
                    if ($title === '' && $dates === '') continue;
                    $ins->execute([$pds_id, $title, $dates, $hours ?: null, $type ?: null, $conducted_by ?: null, $has_certificate ?: null, $venue ?: null, $certificate_details ?: null]);
                }
            }
            
            // References
            $db->prepare("DELETE FROM pds_references WHERE pds_id = ?")->execute([$pds_id]);
            $refs = $_POST['other']['references'] ?? [];
            if (!empty($refs) && is_array($refs)) {
                $ins = $db->prepare("INSERT INTO pds_references (pds_id, name, address, phone) VALUES (?, ?, ?, ?)");
                foreach ($refs as $r) {
                    $name = trim($r['name'] ?? '');
                    $address = trim($r['address'] ?? '');
                    $phone = trim($r['phone'] ?? '');
                    if ($name === '' && $address === '' && $phone === '') continue;
                    $ins->execute([$pds_id, $name, $address, $phone]);
                }
            }
            
            // Delete orphan draft if we replaced an existing submitted/approved PDS
            if ($draftIdToDelete) {
                $db->prepare("DELETE FROM faculty_pds WHERE id = ? AND faculty_id = ? AND status IN ('draft','rejected')")->execute([$draftIdToDelete, $_SESSION['user_id']]);
            }
            
            // Update status to submitted (allow 'submitted','approved' when replacing; otherwise draft/rejected)
            $statusCondition = $existingSubmitted ? "1=1" : "status IN ('draft','rejected')";
            $stmt = $db->prepare("UPDATE faculty_pds SET status = 'submitted', submitted_at = NOW() WHERE id = ? AND faculty_id = ? AND ($statusCondition)");
            $stmt->execute([$pds_id, $_SESSION['user_id']]);
            
            if ($stmt->rowCount() > 0) {
                $db->commit();
                
                // Notify all admins about the PDS submission
                try {
                    // Get faculty name for the notification
                    $facultyStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                    $facultyStmt->execute([$_SESSION['user_id']]);
                    $facultyData = $facultyStmt->fetch();
                    $facultyName = $facultyData ? trim($facultyData['first_name'] . ' ' . $facultyData['last_name']) : 'Faculty Member';
                    
                    $notificationManager = getNotificationManager();
                    $notificationManager->notifyAdminsPDSSubmission(
                        $facultyName,
                        $pds_id
                    );
                } catch (Exception $e) {
                    error_log('Failed to notify admins about PDS submission: ' . $e->getMessage());
                }
                
                logAction('PDS_SUBMIT', "Submitted PDS for review (ID: $pds_id)");
                
                // PRG: set success message and redirect to avoid duplicate submit on reload
                $_SESSION['success'] = 'PDS submitted successfully for review! Your PDS has been sent to the admin and cannot be edited until reviewed.';
                header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
                exit();
            } else {
                throw new Exception('Failed to update PDS status');
            }
        } catch (Exception $e) {
            $db->rollBack();
            error_log('PDS submit error: ' . $e->getMessage());
            
            // Log to file for easier debugging
            try {
                $logDir = __DIR__ . '/../storage/logs';
                if (!is_dir($logDir)) mkdir($logDir, 0755, true);
                $logFile = $logDir . '/pds_error.log';
                $logEntry = date('Y-m-d H:i:s') . ' - PDS submit error: ' . $e->getMessage() . PHP_EOL;
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            } catch (Exception $logErr) {
                // Ignore logging errors
            }
            
            $_SESSION['error'] = 'An error occurred while submitting your PDS. Please try again.';
            header('Location: ' . clean_url($basePath . '/faculty/pds.php', $basePath));
            exit();
        }
    }
}
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
    <title>Personal Data Sheet - WPU Faculty and Staff System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile-button-fix.css', true); ?>" rel="stylesheet">
    <style>
        /* PDS Submission Confirmation Modal Styles */
        .pds-submit-modal {
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }

        .pds-submit-modal-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            border-bottom: none;
            /* padding: 1.5rem 1.75rem; */
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
        }

        .pds-submit-modal-header .btn-close {
            filter: invert(1);
            opacity: 0.9;
            margin-left: auto;
        }

        .pds-submit-modal-header .btn-close:hover {
            opacity: 1;
        }

        .modal-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .pds-submit-modal-header .modal-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0;
            color: #ffffff;
        }

        .pds-submit-modal-body {
            padding: 2rem 1.75rem;
            background: #ffffff;
        }

        .confirmation-message {
            font-size: 1.1rem;
            color: #1e293b;
            margin-bottom: 1.5rem;
            font-weight: 500;
            line-height: 1.6;
        }

        .warning-box {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.1);
        }

        .warning-box i {
            color: #d97706;
            font-size: 1.25rem;
            margin-top: 0.125rem;
            flex-shrink: 0;
        }

        .warning-box span {
            color: #92400e;
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .pds-submit-modal-footer {
            padding: 1.25rem 1.75rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .pds-submit-modal-footer .btn {
            padding: 0.625rem 1.5rem !important;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }

        .pds-submit-modal-footer .btn-outline-secondary {
            border-color: #cbd5e1;
            color: #64748b;
        }

        .pds-submit-modal-footer .btn-outline-secondary:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
            color: #475569;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .pds-submit-modal-footer .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .pds-submit-modal-footer .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }

        .pds-submit-modal-footer .btn-success:active {
            transform: translateY(0);
        }

        /* Modal backdrop */
        #submitPDSModal.modal.show .modal-backdrop {
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
        }

        /* Mobile responsiveness */
        @media (max-width: 575.98px) {
            .pds-submit-modal-header {
                padding: 1.25rem 1.25rem;
            }

            .pds-submit-modal-header .modal-title {
                font-size: 1.15rem;
            }

            .modal-icon-wrapper {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }

            .pds-submit-modal-body {
                padding: 1.5rem 1.25rem;
            }

            .confirmation-message {
                font-size: 1rem;
            }

            .warning-box {
                padding: 0.875rem 1rem;
            }

            .warning-box span {
                font-size: 0.875rem;
            }

            .pds-submit-modal-footer {
                padding: 1rem 1.25rem;
                flex-direction: column-reverse;
            }

            .pds-submit-modal-footer .btn {
                width: 100%;
                padding: 0.75rem 1.25rem !important;
            }
        }

        /* Animation */
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .pds-submit-modal {
            animation: modalFadeIn 0.2s ease-out;
        }

        /* Fix for Bootstrap modal z-index blocking buttons on mobile */
        /* Ensure page buttons are above modal backdrop when modal is closed */
        @media (max-width: 991px) {
            /* Page buttons should be clickable - above modal backdrop (1040) */
            .main-content button,
            .main-content .btn,
            .main-content a.btn,
            .main-content input[type="submit"],
            .main-content input[type="button"] {
                position: relative;
                z-index: 1100 !important;
                pointer-events: auto !important;
            }

            /* Modal backdrop should not block when modal is hidden */
            .modal-backdrop:not(.show) {
                display: none !important;
                pointer-events: none !important;
                z-index: -1 !important;
            }

            /* CONFIRM SUBMISSION modal: ensure it stacks above backdrop (backdrop is 1050) */
            #submitPDSModal.modal.show {
                z-index: 1060 !important;
            }

            /* When modal is showing, ensure modal dialog/content are above backdrop */
            .modal.show .modal-dialog {
                z-index: 1055 !important;
            }

            .modal.show .modal-content,
            .modal.show .modal-content button,
            .modal.show .modal-content .btn {
                z-index: 1056 !important;
                position: relative;
                pointer-events: auto !important;
            }

            /* Ensure modal backdrop doesn't interfere with page content when modal is closed */
            body:not(.modal-open) .modal-backdrop {
                display: none !important;
                pointer-events: none !important;
                z-index: -1 !important;
            }

            /* Override Bootstrap's default modal z-index to prevent blocking */
            .modal:not(.show) {
                z-index: -1 !important;
                pointer-events: none !important;
            }

            .modal:not(.show) .modal-dialog {
                pointer-events: none !important;
            }
        }

        /* Additional fix for all screen sizes - ensure buttons are always interactive */
        button:not(:disabled),
        .btn:not(:disabled),
        a.btn:not(:disabled) {
            position: relative;
            z-index: 10;
        }

        /* When modal is open, ensure modal buttons work */
        .modal.show button,
        .modal.show .btn {
            z-index: 1056 !important;
            position: relative;
        }
        
        /* Remove excessive padding from Cancel button in modals */
        .btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        .btn-outline-secondary[data-bs-dismiss="modal"] {
            padding: 0.375rem 0.75rem !important;
        }
    </style>
</head>
<body class="layout-faculty">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                $currentStatus = $pds['status'] ?? 'draft';
                $isEditable = $currentStatus === 'draft' || $currentStatus === 'rejected';
                ?>
                <div class="page-header">
                    <div class="page-title">
                        <i class="fas fa-file-contract"></i>
                        <span>Personal Data Sheet</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <?php if ($isEditable && $pds && isset($pds['id'])): ?>
                            <button type="button" class="btn btn-success btn-sm" onclick="submitPDS()" data-mobile-fixed="true" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;">
                                <i class="fas fa-paper-plane me-1"></i>Submit for Review
                            </button>
                        <?php endif; ?>
                        <a href="pds_print.php" class="btn btn-outline-primary btn-sm" target="_blank">
                            <i class="fas fa-print me-1"></i>Print PDS
                        </a>
                    </div>
                </div>

                <?php displayMessage(); ?>
                
                <?php if (isset($message) && $message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- PDS Status -->
                <?php if ($pds): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h5 class="mb-1">PDS Status</h5>
                                            <span class="badge badge-<?php 
                                                $status = $pds['status'] ?? 'draft';
                                                echo $status === 'approved' ? 'success' : 
                                                    ($status === 'rejected' ? 'danger' : 
                                                        ($status === 'submitted' ? 'warning' : 'secondary')); 
                                            ?> fs-6">
                                                <?php 
                                                    $status = $pds['status'] ?? 'draft';
                                                    echo $status === 'draft' ? 'Draft (Not Submitted)' : ucfirst($status); 
                                                ?>
                                            </span>
                                            <?php if (!empty($pds['submitted_at'])): ?>
                                                <small class="text-muted ms-2">
                                                    Submitted: <?php echo formatDate($pds['submitted_at'], 'M j, Y g:i A'); ?>
                                                </small>
                                            <?php elseif ($status === 'draft'): ?>
                                                <small class="text-muted ms-2">
                                                    <i class="fas fa-info-circle"></i> Your PDS is saved but not yet submitted for review
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <?php if (($pds['status'] ?? 'draft') === 'rejected' && !empty($pds['admin_notes'])): ?>
                                                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#adminNotesModal">
                                                    <i class="fas fa-comment me-1"></i>View Admin Notes
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Note about required fields -->
                    <div class="row mt-3" style="margin-top: -1rem !important;">
                        <div class="col-12">
                            <div class="alert alert-info mb-0" role="alert" data-no-toast="true">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> Fields marked with (*) are required to fill up.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- PDS Form -->
                <form method="POST" id="pdsForm" action="<?php echo clean_url($basePath . '/faculty/pds.php', $basePath); ?>">
                    <input type="hidden" name="action" value="save">
                    <?php addFormToken(); ?>
                    
                    <!-- Personal Information -->
                    <div class="pds-section">
                        <h4><i class="fas fa-user me-2"></i>Personal Information</h4>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                           value="<?php echo htmlspecialchars($pds['last_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($pds['first_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                           value="<?php echo htmlspecialchars($pds['middle_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="name_extension" class="form-label">Name Extension</label>
                                    <input type="text" class="form-control" id="name_extension" name="name_extension" 
                                           value="<?php echo htmlspecialchars($pds['name_extension'] ?? ''); ?>" placeholder="Jr., Sr., III">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="date_of_birth" class="form-label">Date of Birth *</label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                           value="<?php echo htmlspecialchars($pds['date_of_birth'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="place_of_birth" class="form-label">Place of Birth *</label>
                                    <input type="text" class="form-control" id="place_of_birth" name="place_of_birth" 
                                           value="<?php echo htmlspecialchars($pds['place_of_birth'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="sex" class="form-label">Sex *</label>
                                    <select class="form-control" id="sex" name="sex" required>
                                        <option value="">Select Sex</option>
                                        <option value="Male" <?php echo ($pds['sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($pds['sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="civil_status" class="form-label">Civil Status *</label>
                                    <select class="form-control" id="civil_status" name="civil_status" required>
                                        <option value="">Select Status</option>
                                        <option value="Single" <?php echo ($pds['civil_status'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo ($pds['civil_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo ($pds['civil_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        <option value="Separated" <?php echo ($pds['civil_status'] ?? '') === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                        <option value="Annulled" <?php echo ($pds['civil_status'] ?? '') === 'Annulled' ? 'selected' : ''; ?>>Annulled</option>
                                        <option value="Unknown" <?php echo ($pds['civil_status'] ?? '') === 'Unknown' ? 'selected' : ''; ?>>Unknown</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="height" class="form-label">Height (meters) *</label>
                                    <input type="number" class="form-control" id="height" name="height" step="0.01" 
                                           value="<?php echo htmlspecialchars($pds['height'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="weight" class="form-label">Weight (kg) *</label>
                                    <input type="number" class="form-control" id="weight" name="weight" step="0.01" 
                                           value="<?php echo htmlspecialchars($pds['weight'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="blood_type" class="form-label">Blood Type *</label>
                                    <select class="form-control" id="blood_type" name="blood_type" required>
                                        <option value="">Select Blood Type</option>
                                        <option value="A+" <?php echo ($pds['blood_type'] ?? '') === 'A+' ? 'selected' : ''; ?>>A+</option>
                                        <option value="A-" <?php echo ($pds['blood_type'] ?? '') === 'A-' ? 'selected' : ''; ?>>A-</option>
                                        <option value="B+" <?php echo ($pds['blood_type'] ?? '') === 'B+' ? 'selected' : ''; ?>>B+</option>
                                        <option value="B-" <?php echo ($pds['blood_type'] ?? '') === 'B-' ? 'selected' : ''; ?>>B-</option>
                                        <option value="AB+" <?php echo ($pds['blood_type'] ?? '') === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                        <option value="AB-" <?php echo ($pds['blood_type'] ?? '') === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                        <option value="O+" <?php echo ($pds['blood_type'] ?? '') === 'O+' ? 'selected' : ''; ?>>O+</option>
                                        <option value="O-" <?php echo ($pds['blood_type'] ?? '') === 'O-' ? 'selected' : ''; ?>>O-</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="citizenship" class="form-label">Citizenship *</label>
                                    <input type="text" class="form-control" id="citizenship" name="citizenship" 
                                           value="<?php echo htmlspecialchars($pds['citizenship'] ?? 'Filipino'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="dual_citizenship_country" class="form-label">Dual Citizenship Country (if applicable)</label>
                                    <input type="text" class="form-control" id="dual_citizenship_country" name="dual_citizenship_country" 
                                           value="<?php echo htmlspecialchars($pds['dual_citizenship_country'] ?? ''); ?>" placeholder="Country name">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Government IDs -->
                    <div class="pds-section">
                        <h4><i class="fas fa-id-card me-2"></i>Government IDs</h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="gsis_id" class="form-label">GSIS ID No. *</label>
                                    <input type="text" class="form-control" id="gsis_id" name="gsis_id" 
                                           value="<?php echo htmlspecialchars($pds['gsis_id'] ?? ''); ?>" required> 
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="pagibig_id" class="form-label">Pag-IBIG ID No. *</label>
                                    <input type="text" class="form-control" id="pagibig_id" name="pagibig_id" 
                                           value="<?php echo htmlspecialchars($pds['pagibig_id'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="umid_id" class="form-label">UMID ID No. *</label>
                                    <input type="text" class="form-control" id="umid_id" name="umid_id" 
                                           value="<?php echo htmlspecialchars($pds['umid_id'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="philhealth_id" class="form-label">PhilHealth No. *</label>
                                    <input type="text" class="form-control" id="philhealth_id" name="philhealth_id" 
                                           value="<?php echo htmlspecialchars($pds['philhealth_id'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="philsys_number" class="form-label">PhilSys Number (PSN) *</label>
                                    <input type="text" class="form-control" id="philsys_number" name="philsys_number" 
                                           value="<?php echo htmlspecialchars($pds['philsys_number'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="sss_id" class="form-label">SSS No.</label>
                                    <input type="text" class="form-control" id="sss_id" name="sss_id" 
                                           value="<?php echo htmlspecialchars($pds['sss_id'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="tin" class="form-label">TIN *</label>
                                    <input type="text" class="form-control" id="tin" name="tin" 
                                           value="<?php echo htmlspecialchars($pds['tin'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="agency_employee_no" class="form-label">SAFE Agency Employee No.</label>
                                    <input type="text" class="form-control" id="agency_employee_no" name="agency_employee_no" 
                                           value="<?php echo htmlspecialchars($pds['agency_employee_no'] ?? ''); ?>" disabled>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="agency_employee_id" class="form-label">AGENCY EMPLOYEE ID *</label>
                                    <input type="text" class="form-control" id="agency_employee_id" name="agency_employee_id" 
                                           value="<?php echo htmlspecialchars($pds['agency_employee_id'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- End of Government IDs -->          
                    <!-- Contact Information -->
                    <div class="pds-section">
                        <h4><i class="fas fa-address-book me-2"></i>Contact Information</h4>
                        
                        <h6 class="text-primary mb-3">Residential Address</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="residential_house_no" class="form-label">House/Block/Lot No.</label>
                                    <input type="text" class="form-control" id="residential_house_no" name="residential_house_no" 
                                           value="<?php echo htmlspecialchars($pds['residential_house_no'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="residential_street" class="form-label">Street *</label>
                                    <input type="text" class="form-control" id="residential_street" name="residential_street" 
                                           value="<?php echo htmlspecialchars($pds['residential_street'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="residential_subdivision" class="form-label">Subdivision/Village</label>
                                    <input type="text" class="form-control" id="residential_subdivision" name="residential_subdivision" 
                                           value="<?php echo htmlspecialchars($pds['residential_subdivision'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="residential_barangay" class="form-label">Barangay *</label>
                                    <input type="text" class="form-control" id="residential_barangay" name="residential_barangay" 
                                           value="<?php echo htmlspecialchars($pds['residential_barangay'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="residential_city" class="form-label">City/Municipality *</label>
                                    <input type="text" class="form-control" id="residential_city" name="residential_city" 
                                           value="<?php echo htmlspecialchars($pds['residential_city'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="residential_province" class="form-label">Province *</label>
                                    <input type="text" class="form-control" id="residential_province" name="residential_province" 
                                           value="<?php echo htmlspecialchars($pds['residential_province'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="residential_zipcode" class="form-label">ZIP Code *</label>
                                    <input type="text" class="form-control" id="residential_zipcode" name="residential_zipcode" 
                                           value="<?php echo htmlspecialchars($pds['residential_zipcode'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="residential_address" class="form-label">Full Residential Address (if not using fields above)</label>
                                    <textarea class="form-control" id="residential_address" name="residential_address" rows="2"><?php echo htmlspecialchars($pds['residential_address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="copyAddressBtn" onclick="copyResidentialToPermanent()">
                                    <i class="fas fa-copy me-1"></i>Copy Residential Address to Permanent Address
                                </button>
                                <small class="text-muted ms-2">Click to automatically copy all residential address fields to permanent address</small>
                            </div>
                        </div>
                        
                        <h6 class="text-primary mb-3 mt-4">Permanent Address</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="permanent_house_no" class="form-label">House/Block/Lot No.</label>
                                    <input type="text" class="form-control" id="permanent_house_no" name="permanent_house_no" 
                                           value="<?php echo htmlspecialchars($pds['permanent_house_no'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="permanent_street" class="form-label">Street</label>
                                    <input type="text" class="form-control" id="permanent_street" name="permanent_street" 
                                           value="<?php echo htmlspecialchars($pds['permanent_street'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="permanent_subdivision" class="form-label">Subdivision/Village</label>
                                    <input type="text" class="form-control" id="permanent_subdivision" name="permanent_subdivision" 
                                           value="<?php echo htmlspecialchars($pds['permanent_subdivision'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="permanent_barangay" class="form-label">Barangay</label>
                                    <input type="text" class="form-control" id="permanent_barangay" name="permanent_barangay" 
                                           value="<?php echo htmlspecialchars($pds['permanent_barangay'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="permanent_city" class="form-label">City/Municipality</label>
                                    <input type="text" class="form-control" id="permanent_city" name="permanent_city" 
                                           value="<?php echo htmlspecialchars($pds['permanent_city'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="permanent_province" class="form-label">Province</label>
                                    <input type="text" class="form-control" id="permanent_province" name="permanent_province" 
                                           value="<?php echo htmlspecialchars($pds['permanent_province'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="permanent_zipcode" class="form-label">ZIP Code</label>
                                    <input type="text" class="form-control" id="permanent_zipcode" name="permanent_zipcode" 
                                           value="<?php echo htmlspecialchars($pds['permanent_zipcode'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="permanent_address" class="form-label">Full Permanent Address (if not using fields above)</label>
                                    <textarea class="form-control" id="permanent_address" name="permanent_address" rows="2"><?php echo htmlspecialchars($pds['permanent_address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="residential_telno" class="form-label">Residential Tel. No.</label>
                                    <input type="text" class="form-control" id="residential_telno" name="residential_telno" 
                                           value="<?php echo htmlspecialchars($pds['residential_telno'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="permanent_telno" class="form-label">Permanent Tel. No.</label>
                                    <input type="text" class="form-control" id="permanent_telno" name="permanent_telno" 
                                           value="<?php echo htmlspecialchars($pds['permanent_telno'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <!-- Hidden field to submit email value since disabled fields are not submitted -->
                                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($pds['email'] ?? $_SESSION['user_email'] ?? ''); ?>">
                                    <input type="email" class="form-control" id="email" 
                                           value="<?php echo htmlspecialchars($pds['email'] ?? $_SESSION['user_email'] ?? ''); ?>" 
                                           disabled style="background-color: #f8f9fa; cursor: not-allowed;" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="mobile_no" class="form-label">Mobile No. *</label>
                                    <input type="text" class="form-control" id="mobile_no" name="mobile_no" 
                                           value="<?php echo htmlspecialchars($pds['mobile_no'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email_alt" class="form-label">Email Address (Alternative) *</label>
                                    <input type="email" class="form-control" id="email_alt" name="email_alt" 
                                           value="<?php echo htmlspecialchars($pds['email_alt'] ?? ''); ?>" 
                                           placeholder="Enter alternative email address" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="mobile_no_alt" class="form-label">Mobile No. (Alternative) *</label>
                                    <input type="text" class="form-control" id="mobile_no_alt" name="mobile_no_alt" 
                                           value="<?php echo htmlspecialchars($pds['mobile_no_alt'] ?? ''); ?>" 
                                           placeholder="Enter alternative mobile number" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Family Background -->
                    <div class="pds-section">
                        <h4><i class="fas fa-users me-2"></i>Family Background</h4>
                        
                        <!-- Spouse Information -->
                        <h6 class="text-primary mb-3">Spouse Information</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="spouse_last_name" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="spouse_last_name" name="spouse_last_name" 
                                           value="<?php echo htmlspecialchars($pds['spouse_last_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="spouse_first_name" class="form-label">First Name</label>
                                    <input type="text" class="form-control" id="spouse_first_name" name="spouse_first_name" 
                                           value="<?php echo htmlspecialchars($pds['spouse_first_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="spouse_middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="spouse_middle_name" name="spouse_middle_name" 
                                           value="<?php echo htmlspecialchars($pds['spouse_middle_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="spouse_name_extension" class="form-label">Name Extension</label>
                                    <input type="text" class="form-control" id="spouse_name_extension" name="spouse_name_extension" 
                                           value="<?php echo htmlspecialchars($pds['spouse_name_extension'] ?? ''); ?>" placeholder="Jr., Sr., III">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="spouse_occupation" class="form-label">Occupation</label>
                                    <input type="text" class="form-control" id="spouse_occupation" name="spouse_occupation" 
                                           value="<?php echo htmlspecialchars($pds['spouse_occupation'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="spouse_employer" class="form-label">Employer/Business Name</label>
                                    <input type="text" class="form-control" id="spouse_employer" name="spouse_employer" 
                                           value="<?php echo htmlspecialchars($pds['spouse_employer'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="spouse_business_address" class="form-label">Business Address</label>
                                    <input type="text" class="form-control" id="spouse_business_address" name="spouse_business_address" 
                                           value="<?php echo htmlspecialchars($pds['spouse_business_address'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="spouse_telno" class="form-label">Telephone No.</label>
                                    <input type="text" class="form-control" id="spouse_telno" name="spouse_telno" 
                                           value="<?php echo htmlspecialchars($pds['spouse_telno'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Father's Information -->
                        <h6 class="text-primary mb-3 mt-4">Father's Information</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="father_last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="father_last_name" name="father_last_name" 
                                           value="<?php echo htmlspecialchars($pds['father_last_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="father_first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="father_first_name" name="father_first_name" 
                                           value="<?php echo htmlspecialchars($pds['father_first_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="father_middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="father_middle_name" name="father_middle_name" 
                                           value="<?php echo htmlspecialchars($pds['father_middle_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="father_name_extension" class="form-label">Name Extension</label>
                                    <input type="text" class="form-control" id="father_name_extension" name="father_name_extension" 
                                           value="<?php echo htmlspecialchars($pds['father_name_extension'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Mother's Information -->
                        <h6 class="text-primary mb-3 mt-4">Mother's Maiden Name Information</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="mother_last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="mother_last_name" name="mother_last_name" 
                                           value="<?php echo htmlspecialchars($pds['mother_last_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="mother_first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="mother_first_name" name="mother_first_name" 
                                           value="<?php echo htmlspecialchars($pds['mother_first_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="mother_middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="mother_middle_name" name="mother_middle_name" 
                                           value="<?php echo htmlspecialchars($pds['mother_middle_name'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Children -->
                    <div class="pds-section">
                        <h4><i class="fas fa-child me-2"></i>Children</h4>
                        <div id="childrenList">
                            <?php 
                            $children = [];
                            if ($pds && isset($pds['id'])) {
                                try {
                                    $childrenStmt = $db->prepare("SELECT name, dob FROM pds_children WHERE pds_id = ? ORDER BY id");
                                    $childrenStmt->execute([$pds['id']]);
                                    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                    $children = json_decode($pds['children_info'] ?? '[]', true) ?: [];
                                }
                            } else {
                                $children = json_decode($pds['children_info'] ?? '[]', true) ?: [];
                            }
                            ?>
                            <?php if (empty($children)): ?>
                                <div class="child-row row g-2 mb-2">
                                    <div class="col-md-6">
                                        <input type="text" class="form-control" name="children[0][name]" placeholder="Child's Name">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="date" class="form-control" name="children[0][dob]" placeholder="Date of Birth">
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <button type="button" class="btn btn-danger btn-sm remove-child" style="display:none;">&times;</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($children as $i => $c): ?>
                                    <div class="child-row row g-2 mb-2">
                                        <div class="col-md-6">
                                            <input type="text" class="form-control" name="children[<?php echo $i; ?>][name]" value="<?php echo htmlspecialchars($c['name'] ?? ''); ?>" placeholder="Child's Name">
                                        </div>
                                        <div class="col-md-4">
                                            <input type="date" class="form-control" name="children[<?php echo $i; ?>][dob]" value="<?php echo htmlspecialchars($c['dob'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Date of Birth">
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <button type="button" class="btn btn-danger btn-sm remove-child">&times;</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addChildBtn"><i class="fas fa-plus me-1"></i>Add Child</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-graduation-cap me-2"></i>Educational Background</h4>
                        <div id="educationList">
                            <?php 
                            $education = [];
                            if ($pds && isset($pds['id'])) {
                                try {
                                    $eduStmt = $db->prepare("SELECT level, school, degree, from_date, to_date, units_earned, year_graduated, academic_honors FROM pds_education WHERE pds_id = ? ORDER BY id");
                                    $eduStmt->execute([$pds['id']]);
                                    $education = $eduStmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                    $education = json_decode($pds['educational_background'] ?? '[]', true) ?: [];
                                }
                            } else {
                                $education = json_decode($pds['educational_background'] ?? '[]', true) ?: [];
                            }
                            ?>
                            <?php if (empty($education)): ?>
                                <div class="edu-row mb-3 p-3 border rounded">
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="education[0][level]" placeholder="Level (e.g., College)"></div>
                                        <div class="col-md-6"><input type="text" class="form-control" name="education[0][school]" placeholder="Name of School"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="education[0][degree]" placeholder="Degree/Course"></div>
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="education[0][from_date]" placeholder="From (e.g., 2015)"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="education[0][to_date]" placeholder="To (e.g., 2019)"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="education[0][year_graduated]" placeholder="Year Graduated"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="education[0][units_earned]" placeholder="Units Earned"></div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-11"><input type="text" class="form-control" name="education[0][academic_honors]" placeholder="Academic Honors (if any)"></div>
                                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-edu" style="display:none;">&times;</button></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($education as $i => $e): ?>
                                    <div class="edu-row mb-3 p-3 border rounded">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-3"><input type="text" class="form-control" name="education[<?php echo $i; ?>][level]" value="<?php echo htmlspecialchars($e['level'] ?? ''); ?>" placeholder="Level"></div>
                                            <div class="col-md-6"><input type="text" class="form-control" name="education[<?php echo $i; ?>][school]" value="<?php echo htmlspecialchars($e['school'] ?? ''); ?>" placeholder="School"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="education[<?php echo $i; ?>][degree]" value="<?php echo htmlspecialchars($e['degree'] ?? ''); ?>" placeholder="Degree/Course"></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-3"><input type="text" class="form-control" name="education[<?php echo $i; ?>][from_date]" value="<?php echo htmlspecialchars($e['from_date'] ?? ''); ?>" placeholder="From"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="education[<?php echo $i; ?>][to_date]" value="<?php echo htmlspecialchars($e['to_date'] ?? ''); ?>" placeholder="To"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="education[<?php echo $i; ?>][year_graduated]" value="<?php echo htmlspecialchars($e['year_graduated'] ?? ''); ?>" placeholder="Year Graduated"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="education[<?php echo $i; ?>][units_earned]" value="<?php echo htmlspecialchars($e['units_earned'] ?? ''); ?>" placeholder="Units Earned"></div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-11"><input type="text" class="form-control" name="education[<?php echo $i; ?>][academic_honors]" value="<?php echo htmlspecialchars($e['academic_honors'] ?? ''); ?>" placeholder="Academic Honors"></div>
                                            <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-edu">&times;</button></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addEduBtn"><i class="fas fa-plus me-1"></i>Add Education</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-certificate me-2"></i>Civil Service Eligibility</h4>
                        <div id="eligibilityList">
                            <?php $elig = $pds['civil_service_eligibility_rows'] ?? []; ?>
                            <?php if (empty($elig)): ?>
                                <div class="elig-row row g-2 mb-2">
                                    <div class="col-md-3"><input type="text" class="form-control" name="eligibility[0][title]" placeholder="Title / Eligibility"></div>
                                    <div class="col-md-2"><input type="text" class="form-control" name="eligibility[0][rating]" placeholder="Rating"></div>
                                    <div class="col-md-2"><input type="date" class="form-control" name="eligibility[0][date_of_exam]" placeholder="Date of Exam"></div>
                                    <div class="col-md-3"><input type="text" class="form-control" name="eligibility[0][place_of_exam]" placeholder="Place of Exam"></div>
                                    <div class="col-md-1"><input type="text" class="form-control" name="eligibility[0][license_number]" placeholder="License No."></div>
                                    <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-elig" style="display:none;">&times;</button></div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($elig as $i => $el): ?>
                                    <div class="elig-row row g-2 mb-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="eligibility[<?php echo $i; ?>][title]" value="<?php echo htmlspecialchars($el['title'] ?? ''); ?>" placeholder="Title / Eligibility"></div>
                                        <div class="col-md-2"><input type="text" class="form-control" name="eligibility[<?php echo $i; ?>][rating]" value="<?php echo htmlspecialchars($el['rating'] ?? ''); ?>" placeholder="Rating"></div>
                                        <div class="col-md-2"><input type="date" class="form-control" name="eligibility[<?php echo $i; ?>][date_of_exam]" value="<?php echo htmlspecialchars($el['date_of_exam'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Date of Exam"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="eligibility[<?php echo $i; ?>][place_of_exam]" value="<?php echo htmlspecialchars($el['place_of_exam'] ?? ''); ?>" placeholder="Place of Exam"></div>
                                        <div class="col-md-1"><input type="text" class="form-control" name="eligibility[<?php echo $i; ?>][license_number]" value="<?php echo htmlspecialchars($el['license_number'] ?? ''); ?>" placeholder="License No."></div>
                                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-elig">&times;</button></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addEligBtn"><i class="fas fa-plus me-1"></i>Add Eligibility</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-briefcase me-2"></i>Work Experience</h4>
                        <div id="experienceList">
                            <?php 
                            $experience = [];
                            if ($pds && isset($pds['id'])) {
                                try {
                                    $expStmt = $db->prepare("SELECT dates, position, company, salary, salary_grade, employment_status, appointment_status, gov_service FROM pds_experience WHERE pds_id = ? ORDER BY id");
                                    $expStmt->execute([$pds['id']]);
                                    $experience = $expStmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                    $experience = json_decode($pds['work_experience'] ?? '[]', true) ?: [];
                                }
                            } else {
                                $experience = json_decode($pds['work_experience'] ?? '[]', true) ?: [];
                            }
                            ?>
                            <?php if (empty($experience)): ?>
                                <div class="exp-row mb-3 p-3 border rounded">
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="experience[0][dates]" placeholder="Inclusive Dates"></div>
                                        <div class="col-md-4"><input type="text" class="form-control" name="experience[0][position]" placeholder="Position Title"></div>
                                        <div class="col-md-5"><input type="text" class="form-control" name="experience[0][company]" placeholder="Department/Agency/Company"></div>
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-3"><input type="text" class="form-control" name="experience[0][salary]" placeholder="Monthly Salary"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="experience[0][salary_grade]" placeholder="Salary Grade"></div>
                                        <div class="col-md-3">
                                            <select class="form-control" name="experience[0][employment_status]">
                                                <option value="">Employment Status</option>
                                                <option value="Permanent">Permanent</option>
                                                <option value="Temporary">Temporary</option>
                                                <option value="Contractual">Contractual</option>
                                                <option value="Casual">Casual</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="experience[0][appointment_status]" placeholder="Appointment Status"></div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-11">
                                            <select class="form-control" name="experience[0][gov_service]">
                                                <option value="">Government Service?</option>
                                                <option value="Yes">Yes</option>
                                                <option value="No">No</option>
                                            </select>
                                        </div>
                                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-exp" style="display:none;">&times;</button></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($experience as $i => $ex): ?>
                                    <div class="exp-row mb-3 p-3 border rounded">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-3"><input type="text" class="form-control" name="experience[<?php echo $i; ?>][dates]" value="<?php echo htmlspecialchars($ex['dates'] ?? ''); ?>" placeholder="Inclusive Dates"></div>
                                            <div class="col-md-4"><input type="text" class="form-control" name="experience[<?php echo $i; ?>][position]" value="<?php echo htmlspecialchars($ex['position'] ?? ''); ?>" placeholder="Position Title"></div>
                                            <div class="col-md-5"><input type="text" class="form-control" name="experience[<?php echo $i; ?>][company]" value="<?php echo htmlspecialchars($ex['company'] ?? ''); ?>" placeholder="Department/Agency/Company"></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-3"><input type="text" class="form-control" name="experience[<?php echo $i; ?>][salary]" value="<?php echo htmlspecialchars($ex['salary'] ?? ''); ?>" placeholder="Monthly Salary"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="experience[<?php echo $i; ?>][salary_grade]" value="<?php echo htmlspecialchars($ex['salary_grade'] ?? ''); ?>" placeholder="Salary Grade"></div>
                                            <div class="col-md-3">
                                                <select class="form-control" name="experience[<?php echo $i; ?>][employment_status]">
                                                    <option value="">Employment Status</option>
                                                    <option value="Permanent" <?php echo ($ex['employment_status'] ?? '') === 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                                                    <option value="Temporary" <?php echo ($ex['employment_status'] ?? '') === 'Temporary' ? 'selected' : ''; ?>>Temporary</option>
                                                    <option value="Contractual" <?php echo ($ex['employment_status'] ?? '') === 'Contractual' ? 'selected' : ''; ?>>Contractual</option>
                                                    <option value="Casual" <?php echo ($ex['employment_status'] ?? '') === 'Casual' ? 'selected' : ''; ?>>Casual</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="experience[<?php echo $i; ?>][appointment_status]" value="<?php echo htmlspecialchars($ex['appointment_status'] ?? ''); ?>" placeholder="Appointment Status"></div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-11">
                                                <select class="form-control" name="experience[<?php echo $i; ?>][gov_service]">
                                                    <option value="">Government Service?</option>
                                                    <option value="Yes" <?php echo ($ex['gov_service'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                                    <option value="No" <?php echo ($ex['gov_service'] ?? '') === 'No' ? 'selected' : ''; ?>>No</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-exp">&times;</button></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addExpBtn"><i class="fas fa-plus me-1"></i>Add Experience</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-hands-helping me-2"></i>Voluntary Work / Organization Involvement</h4>
                        <div id="voluntaryList">
                            <?php 
                            $voluntary = [];
                            if ($pds && isset($pds['id'])) {
                                try {
                                    $volStmt = $db->prepare("SELECT org, dates, hours, position FROM pds_voluntary WHERE pds_id = ? ORDER BY id");
                                    $volStmt->execute([$pds['id']]);
                                    $voluntary = $volStmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                    $voluntary = json_decode($pds['voluntary_work'] ?? '[]', true) ?: [];
                                }
                            } else {
                                $voluntary = json_decode($pds['voluntary_work'] ?? '[]', true) ?: [];
                            }
                            ?>
                            <?php if (empty($voluntary)): ?>
                                <div class="vol-row row g-2 mb-2">
                                    <div class="col-md-6"><input type="text" class="form-control" name="voluntary[0][org]" placeholder="Name & Address of Organization"></div>
                                    <div class="col-md-4"><input type="text" class="form-control" name="voluntary[0][dates]" placeholder="Inclusive Dates"></div>
                                    <div class="col-md-2 text-end"><button type="button" class="btn btn-danger btn-sm remove-vol" style="display:none;">&times;</button></div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($voluntary as $i => $v): ?>
                                    <div class="vol-row row g-2 mb-2">
                                        <div class="col-md-6"><input type="text" class="form-control" name="voluntary[<?php echo $i; ?>][org]" value="<?php echo htmlspecialchars($v['org'] ?? ''); ?>" placeholder="Organization"></div>
                                        <div class="col-md-4"><input type="text" class="form-control" name="voluntary[<?php echo $i; ?>][dates]" value="<?php echo htmlspecialchars($v['dates'] ?? ''); ?>" placeholder="Inclusive Dates"></div>
                                        <div class="col-md-2 text-end"><button type="button" class="btn btn-danger btn-sm remove-vol">&times;</button></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addVolBtn"><i class="fas fa-plus me-1"></i>Add Entry</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-chalkboard-teacher me-2"></i>Learning and Development (L&D)</h4>
                        <div id="learningList">
                            <?php 
                            $learning = [];
                            if ($pds && isset($pds['id'])) {
                                try {
                                    $ldStmt = $db->prepare("SELECT title, dates, hours, type, conducted_by, has_certificate, venue, certificate_details FROM pds_learning WHERE pds_id = ? ORDER BY id");
                                    $ldStmt->execute([$pds['id']]);
                                    $learning = $ldStmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (Exception $e) {
                                    $learning = json_decode($pds['learning_development'] ?? '[]', true) ?: [];
                                }
                            } else {
                                $learning = json_decode($pds['learning_development'] ?? '[]', true) ?: [];
                            }
                            ?>
                            <?php if (empty($learning)): ?>
                                <div class="ld-row mb-3 p-3 border rounded">
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6"><input type="text" class="form-control" name="learning[0][title]" placeholder="Title of L&D/Training"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="learning[0][dates]" placeholder="Inclusive Dates"></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="learning[0][hours]" placeholder="No. of Hours"></div>
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-4">
                                            <select class="form-control" name="learning[0][type]">
                                                <option value="">Type of LD</option>
                                                <option value="Training">Training</option>
                                                <option value="Seminar">Seminar</option>
                                                <option value="Workshop">Workshop</option>
                                                <option value="Conference">Conference</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4"><input type="text" class="form-control" name="learning[0][conducted_by]" placeholder="Conducted By"></div>
                                        <div class="col-md-4"><input type="text" class="form-control" name="learning[0][venue]" placeholder="Venue"></div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <select class="form-control" name="learning[0][has_certificate]">
                                                <option value="">Has Certificate?</option>
                                                <option value="Yes">Yes</option>
                                                <option value="No">No</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8"><input type="text" class="form-control" name="learning[0][certificate_details]" placeholder="Certificate Details (if any)"></div>
                                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-ld" style="display:none;">&times;</button></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($learning as $i => $l): ?>
                                    <div class="ld-row mb-3 p-3 border rounded">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6"><input type="text" class="form-control" name="learning[<?php echo $i; ?>][title]" value="<?php echo htmlspecialchars($l['title'] ?? ''); ?>" placeholder="Title"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="learning[<?php echo $i; ?>][dates]" value="<?php echo htmlspecialchars($l['dates'] ?? ''); ?>" placeholder="Dates"></div>
                                            <div class="col-md-3"><input type="text" class="form-control" name="learning[<?php echo $i; ?>][hours]" value="<?php echo htmlspecialchars($l['hours'] ?? ''); ?>" placeholder="Hours"></div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-4">
                                                <select class="form-control" name="learning[<?php echo $i; ?>][type]">
                                                    <option value="">Type of LD</option>
                                                    <option value="Training" <?php echo ($l['type'] ?? '') === 'Training' ? 'selected' : ''; ?>>Training</option>
                                                    <option value="Seminar" <?php echo ($l['type'] ?? '') === 'Seminar' ? 'selected' : ''; ?>>Seminar</option>
                                                    <option value="Workshop" <?php echo ($l['type'] ?? '') === 'Workshop' ? 'selected' : ''; ?>>Workshop</option>
                                                    <option value="Conference" <?php echo ($l['type'] ?? '') === 'Conference' ? 'selected' : ''; ?>>Conference</option>
                                                    <option value="Other" <?php echo ($l['type'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4"><input type="text" class="form-control" name="learning[<?php echo $i; ?>][conducted_by]" value="<?php echo htmlspecialchars($l['conducted_by'] ?? ''); ?>" placeholder="Conducted By"></div>
                                            <div class="col-md-4"><input type="text" class="form-control" name="learning[<?php echo $i; ?>][venue]" value="<?php echo htmlspecialchars($l['venue'] ?? ''); ?>" placeholder="Venue"></div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-3">
                                                <select class="form-control" name="learning[<?php echo $i; ?>][has_certificate]">
                                                    <option value="">Has Certificate?</option>
                                                    <option value="Yes" <?php echo ($l['has_certificate'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                                    <option value="No" <?php echo ($l['has_certificate'] ?? '') === 'No' ? 'selected' : ''; ?>>No</option>
                                                </select>
                                            </div>
                                            <div class="col-md-8"><input type="text" class="form-control" name="learning[<?php echo $i; ?>][certificate_details]" value="<?php echo htmlspecialchars($l['certificate_details'] ?? ''); ?>" placeholder="Certificate Details"></div>
                                            <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-ld">&times;</button></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addLdBtn"><i class="fas fa-plus me-1"></i>Add L&D</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-ellipsis-h me-2"></i>Other Information</h4>
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Special Skills and Hobbies</label>
                                <textarea class="form-control" name="other[skills]" rows="2"><?php echo htmlspecialchars(is_array($other) ? ($other['skills'] ?? '') : ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Non-Academic Distinctions / Recognition</label>
                                <textarea class="form-control" name="other[distinctions]" rows="2"><?php echo htmlspecialchars(is_array($other) ? ($other['distinctions'] ?? '') : ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <label class="form-label">Membership in Association/Organization</label>
                                <textarea class="form-control" name="other[memberships]" rows="2"><?php echo htmlspecialchars(is_array($other) ? ($other['memberships'] ?? '') : ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <?php
                    $aq = [];
                    if ($pds && isset($pds['additional_questions'])) {
                        $aq = json_decode($pds['additional_questions'], true) ?: [];
                    }
                    ?>
                    <div class="pds-section">
                        <h4><i class="fas fa-question-circle me-2"></i>Additional Questions</h4>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">34. Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed:</label>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label class="form-label small">a. within the third degree?</label>
                                        <select class="form-control" name="additional_questions[related_authority_third]">
                                            <option value="">Select</option>
                                            <option value="Yes" <?php echo ($aq['related_authority_third'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                            <option value="No" <?php echo ($aq['related_authority_third'] ?? '') === 'No' ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">b. within the fourth degree (for Local Government Unit - Career Employees)?</label>
                                        <select class="form-control" name="additional_questions[related_authority_fourth]">
                                            <option value="">Select</option>
                                            <option value="Yes" <?php echo ($aq['related_authority_fourth'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                            <option value="No" <?php echo ($aq['related_authority_fourth'] ?? '') === 'No' ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label small">If YES, give details:</label>
                                    <input type="text" class="form-control" name="additional_questions[related_authority_details]" value="<?php echo htmlspecialchars($aq['related_authority_details'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">35. a. Have you ever been found guilty of any administrative offense?</label>
                                <textarea class="form-control" name="additional_questions[found_guilty_admin]" rows="2" placeholder="If YES, give details"><?php echo htmlspecialchars($aq['found_guilty_admin'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">35. b. Have you been criminally charged before any court?</label>
                                <textarea class="form-control" name="additional_questions[criminally_charged]" rows="2" placeholder="If YES, give details"><?php echo htmlspecialchars($aq['criminally_charged'] ?? ''); ?></textarea>
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label class="form-label small">Date Filed:</label>
                                        <input type="date" class="form-control" name="additional_questions[criminal_charge_date]" value="<?php echo htmlspecialchars($aq['criminal_charge_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Status of Case/s:</label>
                                        <input type="text" class="form-control" name="additional_questions[criminal_charge_status]" value="<?php echo htmlspecialchars($aq['criminal_charge_status'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">36. Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?</label>
                                <textarea class="form-control" name="additional_questions[convicted_crime]" rows="2" placeholder="If YES, give details"><?php echo htmlspecialchars($aq['convicted_crime'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">37. Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?</label>
                                <textarea class="form-control" name="additional_questions[separated_service]" rows="2" placeholder="If YES, give details"><?php echo htmlspecialchars($aq['separated_service'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">38. a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?</label>
                                <textarea class="form-control" name="additional_questions[candidate_election]" rows="2" placeholder="If YES, give details"><?php echo htmlspecialchars($aq['candidate_election'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">38. b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a candidate or party?</label>
                                <textarea class="form-control" name="additional_questions[resigned_for_election]" rows="2" placeholder="If YES, give details"><?php echo htmlspecialchars($aq['resigned_for_election'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">39. Have you acquired the status of an immigrant or permanent resident of another country?</label>
                                <textarea class="form-control" name="additional_questions[immigrant_status]" rows="2" placeholder="If YES, give details (country)"><?php echo htmlspecialchars($aq['immigrant_status'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">40. Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277); and (c) Solo Parents Welfare Act of 2000 (RA 8972):</label>
                                <div class="row mt-2">
                                    <div class="col-md-4">
                                        <label class="form-label small">a. Are you a member of any indigenous group?</label>
                                        <input type="text" class="form-control" name="additional_questions[indigenous_group]" value="<?php echo htmlspecialchars($aq['indigenous_group'] ?? ''); ?>" placeholder="If YES, please specify">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">b. Are you a person with disability?</label>
                                        <input type="text" class="form-control" name="additional_questions[person_with_disability]" value="<?php echo htmlspecialchars($aq['person_with_disability'] ?? ''); ?>" placeholder="If YES, please specify ID No">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">c. Are you a solo parent?</label>
                                        <input type="text" class="form-control" name="additional_questions[solo_parent]" value="<?php echo htmlspecialchars($aq['solo_parent'] ?? ''); ?>" placeholder="If YES, please specify ID No">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-id-badge me-2"></i>Position & Declaration</h4>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Current Position/Designation</label>
                                <select class="form-control" name="position" id="pds_position">
                                    <option value="">Select Position</option>
                                    <?php foreach ($allPositions as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p['position_title']); ?>" 
                                                data-salary-grade="<?php echo $p['salary_grade']; ?>"
                                                data-annual-salary="<?php echo $p['annual_salary']; ?>"
                                                <?php echo (($pds['position'] ?? '') === $p['position_title']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['position_title']); ?> 
                                            (SG-<?php echo $p['salary_grade']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted" id="pds-position-details"></small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date Accomplished</label>
                                <input type="date" class="form-control" name="date_accomplished" value="<?php echo htmlspecialchars($pds['date_accomplished'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Sworn Date</label>
                                <input type="date" class="form-control" name="sworn_date" value="<?php echo htmlspecialchars($pds['sworn_date'] ?? date('Y-m-d')); ?>">
                            </div>
                        </div>
                        <h6 class="text-primary mb-3">Government Issued ID</h6>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">ID/License/Passport No.</label>
                                <input type="text" class="form-control" name="government_id_number" value="<?php echo htmlspecialchars($pds['government_id_number'] ?? ''); ?>" placeholder="e.g., Passport, GSIS, SSS, PRC, Driver's License">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Issuance</label>
                                <input type="date" class="form-control" name="government_id_issue_date" value="<?php echo htmlspecialchars($pds['government_id_issue_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Place of Issuance</label>
                                <input type="text" class="form-control" name="government_id_issue_place" value="<?php echo htmlspecialchars($pds['government_id_issue_place'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="pds-section">
                        <h4><i class="fas fa-address-book me-2"></i>References</h4>
                        <div id="referencesList">
                            <?php $refs = is_array($other) ? ($other['references'] ?? []) : []; ?>
                            <?php if (empty($refs)): ?>
                                <div class="ref-row row g-2 mb-2">
                                    <div class="col-md-4"><input type="text" class="form-control" name="other[references][0][name]" placeholder="Name"></div>
                                    <div class="col-md-5"><input type="text" class="form-control" name="other[references][0][address]" placeholder="Address"></div>
                                    <div class="col-md-2"><input type="text" class="form-control" name="other[references][0][phone]" placeholder="Telephone No."></div>
                                    <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-ref" style="display:none;">&times;</button></div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($refs as $i => $r): ?>
                                    <div class="ref-row row g-2 mb-2">
                                        <div class="col-md-4"><input type="text" class="form-control" name="other[references][<?php echo $i; ?>][name]" value="<?php echo htmlspecialchars($r['name'] ?? ''); ?>" placeholder="Name"></div>
                                        <div class="col-md-5"><input type="text" class="form-control" name="other[references][<?php echo $i; ?>][address]" value="<?php echo htmlspecialchars($r['address'] ?? ''); ?>" placeholder="Address"></div>
                                        <div class="col-md-2"><input type="text" class="form-control" name="other[references][<?php echo $i; ?>][phone]" value="<?php echo htmlspecialchars($r['phone'] ?? ''); ?>" placeholder="Telephone No."></div>
                                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-ref">&times;</button></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addRefBtn"><i class="fas fa-plus me-1"></i>Add Reference</button>
                        </div>
                    </div>

                    <div class="pds-section">
                        <div class="row">
                            <div class="col-12">
                                <div class="d-flex justify-content-center align-items-center flex-nowrap gap-3">
                                <?php if ($isEditable): ?>
                                    <button type="submit" class="btn btn-primary btn-lg" id="saveDraftBtn" data-mobile-fixed="true" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;">
                                        <i class="fas fa-save me-md-2"></i><span class="d-none d-md-inline">Save as Draft</span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg" disabled>
                                        <i class="fas fa-lock me-md-2"></i><span class="d-none d-md-inline">Cannot Edit (<?php echo ucfirst($currentStatus); ?>)</span>
                                    </button>
                                <?php endif; ?>
                                
                                <a href="dashboard.php" class="btn btn-outline-secondary btn-lg" data-mobile-fixed="true" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;">
                                    <i class="fas fa-arrow-left me-md-2"></i><span class="d-none d-md-inline">Back to Dashboard</span>
                                </a>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 text-center">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <?php if ($isEditable): ?>
                                        <strong>Save as Draft:</strong> Saves your progress without submitting for review. You can continue editing anytime.
                                        <?php if ($pds && isset($pds['id'])): ?>
                                            <br><strong>Submit for Review:</strong> Sends your PDS to admin for approval. You cannot edit after submission until reviewed.
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Your PDS is currently <strong><?php echo ucfirst($currentStatus); ?></strong> and cannot be edited at this time.
                                        <?php if ($currentStatus === 'submitted'): ?>
                                            Please wait for admin review.
                                        <?php elseif ($currentStatus === 'approved'): ?>
                                            Your PDS has been approved and is now read-only.
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <!-- Admin Notes Modal -->
    <?php if ($pds && isset($pds['id']) && ($pds['status'] ?? 'draft') === 'rejected' && !empty($pds['admin_notes'])): ?>
        <div class="modal fade" id="adminNotesModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Admin Notes</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p><?php echo nl2br(htmlspecialchars($pds['admin_notes'])); ?></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- PDS Submission Confirmation Modal -->
    <div class="modal fade" id="submitPDSModal" tabindex="-1" aria-labelledby="submitPDSModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content pds-submit-modal">
                <div class="modal-header pds-submit-modal-header">
                    <div class="modal-icon-wrapper">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h5 class="modal-title" id="submitPDSModalLabel">Confirm Submission</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pds-submit-modal-body">
                    <p class="confirmation-message">
                        <strong>Are you sure you want to submit your PDS for review?</strong>
                    </p>
                    <div class="alert alert-warning mb-0">
                        <ul class="mb-0 ps-3">
                            <li><strong>Your PDS will be sent to the admin</strong> for review and approval</li>
                            <li><strong>You will not be able to edit</strong> your PDS after submission until it has been reviewed</li>
                            <li>If you need to make changes, click "Cancel" and use <strong>"Save as Draft"</strong> instead</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer pds-submit-modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="confirmSubmitBtn">
                        <i class="fas fa-paper-plane me-2"></i>Yes, Submit for Review
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        // Move Confirm Submission modal to body so it stacks above Bootstrap's backdrop (which is appended to body)
        document.addEventListener('DOMContentLoaded', function() {
            const submitModal = document.getElementById('submitPDSModal');
            if (submitModal && submitModal.parentNode !== document.body) {
                document.body.appendChild(submitModal);
            }
        });

        // Position selection handler - display salary info
        document.addEventListener('DOMContentLoaded', function() {
            const positionSelect = document.getElementById('pds_position');
            const positionDetails = document.getElementById('pds-position-details');
            
            if (positionSelect && positionDetails) {
                // Display initial details if position is already selected
                const initialOption = positionSelect.options[positionSelect.selectedIndex];
                if (initialOption.value && initialOption.dataset.salaryGrade) {
                    const salaryGrade = initialOption.dataset.salaryGrade;
                    const annualSalary = parseFloat(initialOption.dataset.annualSalary);
                    const monthlySalary = annualSalary / 12;
                    
                    positionDetails.innerHTML = `<i class="fas fa-info-circle me-1"></i>Salary Grade ${salaryGrade}: ₱${annualSalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/year (₱${monthlySalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/month)`;
                    positionDetails.classList.add('text-success');
                }
                
                positionSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        const salaryGrade = selectedOption.dataset.salaryGrade;
                        const annualSalary = parseFloat(selectedOption.dataset.annualSalary);
                        const monthlySalary = annualSalary / 12;
                        
                        positionDetails.innerHTML = `<i class="fas fa-info-circle me-1"></i>Salary Grade ${salaryGrade}: ₱${annualSalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/year (₱${monthlySalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/month)`;
                        positionDetails.classList.add('text-success');
                    } else {
                        positionDetails.innerHTML = '';
                        positionDetails.classList.remove('text-success');
                    }
                });
            }
            
            // Disable form fields if PDS is not editable (submitted, approved, etc.)
            const pdsForm = document.getElementById('pdsForm');
            <?php if (!$isEditable): ?>
            if (pdsForm) {
                // Disable all input, select, and textarea fields
                const formElements = pdsForm.querySelectorAll('input:not([type="hidden"]), select, textarea');
                formElements.forEach(element => {
                    // Skip the employee_id field as it's already disabled
                    if (element.id !== 'agency_employee_no') {
                        element.disabled = true;
                        element.style.backgroundColor = '#f8f9fa';
                        element.style.cursor = 'not-allowed';
                    }
                });
                
                // Disable all dynamic add buttons (Add Child, Add Education, etc.)
                const addButtons = pdsForm.querySelectorAll('button[id^="add"], button.btn-outline-primary');
                addButtons.forEach(button => {
                    button.disabled = true;
                    button.style.cursor = 'not-allowed';
                });
                
                // Disable all remove buttons in dynamic sections
                const removeButtons = pdsForm.querySelectorAll('button[onclick^="remove"]');
                removeButtons.forEach(button => {
                    button.disabled = true;
                    button.style.cursor = 'not-allowed';
                });
            }
            <?php endif; ?>
            
            // Handle PDS submission confirmation (with double-submit prevention)
            const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
            if (confirmSubmitBtn) {
                confirmSubmitBtn.addEventListener('click', function() {
                    if (confirmSubmitBtn.disabled) return; // Already submitting
                    const form = document.getElementById('pdsForm');
                    const actionInput = form.querySelector('input[name="action"]');
                    if (form && actionInput) {
                        confirmSubmitBtn.disabled = true;
                        confirmSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Submitting...';
                        actionInput.value = 'submit';
                        // Re-enable required validation for submission
                        const requiredFields = form.querySelectorAll('[required]');
                        requiredFields.forEach(field => {
                            field.removeAttribute('data-draft-save');
                        });
                        form.submit();
                    }
                });
            }
            
            // Handle "Save as Draft" button - remove required validation
            const saveDraftBtn = document.getElementById('saveDraftBtn');
            if (pdsForm && saveDraftBtn) {
                saveDraftBtn.addEventListener('click', function(e) {
                    // Temporarily remove required attributes for draft save
                    const requiredFields = pdsForm.querySelectorAll('[required]');
                    requiredFields.forEach(field => {
                        field.setAttribute('data-draft-save', 'true');
                        field.removeAttribute('required');
                    });
                    
                    // Re-add required attributes after form submission to restore validation
                    setTimeout(() => {
                        requiredFields.forEach(field => {
                            if (field.getAttribute('data-draft-save') === 'true') {
                                field.setAttribute('required', 'required');
                                field.removeAttribute('data-draft-save');
                            }
                        });
                    }, 100);
                });
            }
            
            // Handle form submission to ensure proper action value and prevent double-submit
            if (pdsForm) {
                pdsForm.addEventListener('submit', function(e) {
                    // Prevent double-submit (CSRF token is single-use)
                    if (pdsForm.dataset.submitting === 'true') {
                        e.preventDefault();
                        return false;
                    }

                    // Warn if form has too many fields (PHP max_input_vars protection)
                    var allInputs = pdsForm.querySelectorAll('input[name], select[name], textarea[name]');
                    var maxVars = <?php echo (int) ini_get('max_input_vars'); ?>;
                    if (maxVars > 0 && allInputs.length > maxVars * 0.9) {
                        if (!confirm('Warning: Your form has ' + allInputs.length + ' fields, which is close to the server limit (' + maxVars + '). Some data might not be saved. Continue anyway?')) {
                            e.preventDefault();
                            return false;
                        }
                    }

                    pdsForm.dataset.submitting = 'true';
                    // Disable buttons immediately to prevent double-click
                    if (saveDraftBtn) {
                        saveDraftBtn.disabled = true;
                        saveDraftBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-md-2" role="status"></span><span class="d-none d-md-inline">Saving...</span>';
                    }
                    if (confirmSubmitBtn) {
                        confirmSubmitBtn.disabled = true;
                    }
                    // If Save as Draft button was clicked, ensure action is 'save'
                    if (saveDraftBtn && document.activeElement === saveDraftBtn) {
                        const actionInput = pdsForm.querySelector('input[name="action"]');
                        if (actionInput) {
                            actionInput.value = 'save';
                        }
                    }
                });
            }
        });
        
        function submitPDS() {
            const form = document.getElementById('pdsForm');
            if (!form) return;
            const requiredFields = form.querySelectorAll('[required]');
            const missing = [];
            let firstInvalid = null;
            requiredFields.forEach(function(field) {
                const val = (field.value || '').trim();
                const name = field.name || field.id || '';
                if (val === '' && name !== '') {
                    const labelEl = form.querySelector('label[for="' + field.id + '"]') || field.closest('.form-group')?.querySelector('label');
                    const label = labelEl ? (labelEl.textContent || '').trim().replace(/\s*\*\s*$/, '') : name;
                    if (label && missing.indexOf(label) === -1) missing.push(label);
                    if (!firstInvalid) firstInvalid = field;
                }
            });
            if (missing.length > 0) {
                const msg = 'Please fill in all required fields (*) before submitting. Missing: ' + missing.slice(0, 5).join(', ') + (missing.length > 5 ? ' and ' + (missing.length - 5) + ' more.' : '.');
                alert(msg);
                if (firstInvalid) {
                    firstInvalid.focus();
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }
            const modalElement = document.getElementById('submitPDSModal');
            if (modalElement) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }
        
        // Dynamic rows for PDS repeatable sections
        function initDynamicRows() {
            // Children
            document.getElementById('addChildBtn')?.addEventListener('click', function() {
                const container = document.getElementById('childrenList');
                const index = container.querySelectorAll('.child-row').length;
                const div = document.createElement('div');
                div.className = 'child-row row g-2 mb-2';
                div.innerHTML = `
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="children[${index}][name]" placeholder="Child's Name">
                    </div>
                    <div class="col-md-4">
                        <input type="date" class="form-control" name="children[${index}][dob]">
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="button" class="btn btn-danger btn-sm remove-child">&times;</button>
                    </div>
                `;
                container.appendChild(div);
            });

            document.body.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('remove-child')) {
                    e.target.closest('.child-row').remove();
                }
                if (e.target && e.target.classList.contains('remove-exp')) {
                    e.target.closest('.exp-row').remove();
                }
                if (e.target && e.target.classList.contains('remove-edu')) {
                    e.target.closest('.edu-row').remove();
                }
                if (e.target && e.target.classList.contains('remove-elig')) {
                    e.target.closest('.elig-row').remove();
                }
                if (e.target && e.target.classList.contains('remove-ld')) {
                    e.target.closest('.ld-row').remove();
                }
                if (e.target && e.target.classList.contains('remove-vol')) {
                    e.target.closest('.vol-row').remove();
                }
                if (e.target && e.target.classList.contains('remove-ref')) {
                    e.target.closest('.ref-row').remove();
                }
            });

            // Education
            document.getElementById('addEduBtn')?.addEventListener('click', function() {
                const container = document.getElementById('educationList');
                const index = container.querySelectorAll('.edu-row').length;
                const div = document.createElement('div');
                div.className = 'edu-row mb-3 p-3 border rounded';
                div.innerHTML = `
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><input type="text" class="form-control" name="education[${index}][level]" placeholder="Level"></div>
                        <div class="col-md-6"><input type="text" class="form-control" name="education[${index}][school]" placeholder="School"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="education[${index}][degree]" placeholder="Degree/Course"></div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><input type="text" class="form-control" name="education[${index}][from_date]" placeholder="From"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="education[${index}][to_date]" placeholder="To"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="education[${index}][year_graduated]" placeholder="Year Graduated"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="education[${index}][units_earned]" placeholder="Units Earned"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-11"><input type="text" class="form-control" name="education[${index}][academic_honors]" placeholder="Academic Honors"></div>
                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-edu">&times;</button></div>
                    </div>
                `;
                container.appendChild(div);
            });

            // Civil Service Eligibility
            document.getElementById('addEligBtn')?.addEventListener('click', function() {
                const container = document.getElementById('eligibilityList');
                const index = container.querySelectorAll('.elig-row').length;
                const div = document.createElement('div');
                div.className = 'elig-row row g-2 mb-2';
                div.innerHTML = `
                    <div class="col-md-3"><input type="text" class="form-control" name="eligibility[${index}][title]" placeholder="Title / Eligibility"></div>
                    <div class="col-md-2"><input type="text" class="form-control" name="eligibility[${index}][rating]" placeholder="Rating"></div>
                    <div class="col-md-2"><input type="date" class="form-control" name="eligibility[${index}][date_of_exam]" placeholder="Date of Exam"></div>
                    <div class="col-md-3"><input type="text" class="form-control" name="eligibility[${index}][place_of_exam]" placeholder="Place of Exam"></div>
                    <div class="col-md-1"><input type="text" class="form-control" name="eligibility[${index}][license_number]" placeholder="License No."></div>
                    <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-elig">&times;</button></div>
                `;
                container.appendChild(div);
            });

            // Experience
            document.getElementById('addExpBtn')?.addEventListener('click', function() {
                const container = document.getElementById('experienceList');
                const index = container.querySelectorAll('.exp-row').length;
                const div = document.createElement('div');
                div.className = 'exp-row mb-3 p-3 border rounded';
                div.innerHTML = `
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><input type="text" class="form-control" name="experience[${index}][dates]" placeholder="Inclusive Dates"></div>
                        <div class="col-md-4"><input type="text" class="form-control" name="experience[${index}][position]" placeholder="Position Title"></div>
                        <div class="col-md-5"><input type="text" class="form-control" name="experience[${index}][company]" placeholder="Department/Agency/Company"></div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><input type="text" class="form-control" name="experience[${index}][salary]" placeholder="Monthly Salary"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="experience[${index}][salary_grade]" placeholder="Salary Grade"></div>
                        <div class="col-md-3">
                            <select class="form-control" name="experience[${index}][employment_status]">
                                <option value="">Employment Status</option>
                                <option value="Permanent">Permanent</option>
                                <option value="Temporary">Temporary</option>
                                <option value="Contractual">Contractual</option>
                                <option value="Casual">Casual</option>
                            </select>
                        </div>
                        <div class="col-md-3"><input type="text" class="form-control" name="experience[${index}][appointment_status]" placeholder="Appointment Status"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-11">
                            <select class="form-control" name="experience[${index}][gov_service]">
                                <option value="">Government Service?</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-exp">&times;</button></div>
                    </div>
                `;
                container.appendChild(div);
            });

            // Voluntary
            document.getElementById('addVolBtn')?.addEventListener('click', function() {
                const container = document.getElementById('voluntaryList');
                const index = container.querySelectorAll('.vol-row').length;
                const div = document.createElement('div');
                div.className = 'vol-row row g-2 mb-2';
                div.innerHTML = `
                    <div class="col-md-6"><input type="text" class="form-control" name="voluntary[${index}][org]" placeholder="Organization"></div>
                    <div class="col-md-4"><input type="text" class="form-control" name="voluntary[${index}][dates]" placeholder="Inclusive Dates"></div>
                    <div class="col-md-2 text-end"><button type="button" class="btn btn-danger btn-sm remove-vol">&times;</button></div>
                `;
                container.appendChild(div);
            });

            // Learning and Development
            document.getElementById('addLdBtn')?.addEventListener('click', function() {
                const container = document.getElementById('learningList');
                const index = container.querySelectorAll('.ld-row').length;
                const div = document.createElement('div');
                div.className = 'ld-row mb-3 p-3 border rounded';
                div.innerHTML = `
                    <div class="row g-2 mb-2">
                        <div class="col-md-6"><input type="text" class="form-control" name="learning[${index}][title]" placeholder="Title of L&D/Training"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="learning[${index}][dates]" placeholder="Inclusive Dates"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="learning[${index}][hours]" placeholder="No. of Hours"></div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <select class="form-control" name="learning[${index}][type]">
                                <option value="">Type of LD</option>
                                <option value="Training">Training</option>
                                <option value="Seminar">Seminar</option>
                                <option value="Workshop">Workshop</option>
                                <option value="Conference">Conference</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4"><input type="text" class="form-control" name="learning[${index}][conducted_by]" placeholder="Conducted By"></div>
                        <div class="col-md-4"><input type="text" class="form-control" name="learning[${index}][venue]" placeholder="Venue"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <select class="form-control" name="learning[${index}][has_certificate]">
                                <option value="">Has Certificate?</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div class="col-md-8"><input type="text" class="form-control" name="learning[${index}][certificate_details]" placeholder="Certificate Details (if any)"></div>
                        <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-ld">&times;</button></div>
                    </div>
                `;
                container.appendChild(div);
            });

            // References
            document.getElementById('addRefBtn')?.addEventListener('click', function() {
                const container = document.getElementById('referencesList');
                const index = container.querySelectorAll('.ref-row').length;
                const div = document.createElement('div');
                div.className = 'ref-row row g-2 mb-2';
                div.innerHTML = `
                    <div class="col-md-4"><input type="text" class="form-control" name="other[references][${index}][name]" placeholder="Name"></div>
                    <div class="col-md-5"><input type="text" class="form-control" name="other[references][${index}][address]" placeholder="Address"></div>
                    <div class="col-md-2"><input type="text" class="form-control" name="other[references][${index}][phone]" placeholder="Telephone No."></div>
                    <div class="col-md-1 text-end"><button type="button" class="btn btn-danger btn-sm remove-ref">&times;</button></div>
                `;
                container.appendChild(div);
            });
        }

        // Initialize dynamic rows after DOM ready
        document.addEventListener('DOMContentLoaded', initDynamicRows);
        
        // ============================================================
        // PDS Auto-Save: periodically saves form data as draft via AJAX
        // so users never lose their work.
        // ============================================================
        (function() {
            const AUTO_SAVE_INTERVAL = 60000; // every 60 seconds
            const form = document.getElementById('pdsForm');
            if (!form) return;

            // Only auto-save if PDS is editable
            const isEditable = <?php echo json_encode(($pds['status'] ?? 'draft') === 'draft' || ($pds['status'] ?? 'draft') === 'rejected'); ?>;
            if (!isEditable) return;

            let autoSaveTimer = null;
            let lastSavedData = '';
            let isSaving = false;

            function getFormDataString() {
                return new URLSearchParams(new FormData(form)).toString();
            }

            function showAutoSaveStatus(message, isError) {
                let indicator = document.getElementById('autoSaveIndicator');
                if (!indicator) {
                    indicator = document.createElement('div');
                    indicator.id = 'autoSaveIndicator';
                    indicator.style.cssText = 'position:fixed;bottom:20px;right:20px;padding:8px 16px;border-radius:8px;font-size:13px;z-index:9999;transition:opacity 0.5s;box-shadow:0 2px 8px rgba(0,0,0,0.15);';
                    document.body.appendChild(indicator);
                }
                indicator.textContent = message;
                indicator.style.background = isError ? '#dc3545' : '#198754';
                indicator.style.color = '#fff';
                indicator.style.opacity = '1';
                setTimeout(function() { indicator.style.opacity = '0'; }, 4000);
            }

            function performAutoSave(retryCount) {
                if (isSaving) return;
                const currentData = getFormDataString();
                if (currentData === lastSavedData) return; // nothing changed

                retryCount = retryCount || 0;
                const maxRetries = 2;
                isSaving = true;
                const formData = new FormData(form);
                formData.set('action', 'auto_save');

                const url = '<?php echo clean_url($basePath . "/faculty/auto_save_pds.php", $basePath); ?>';
                const controller = new AbortController();
                const timeoutId = setTimeout(function() { controller.abort(); }, 30000); // 30s timeout

                fetch(url, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(resp) {
                    clearTimeout(timeoutId);
                    var status = resp.status;
                    return resp.text().then(function(text) {
                        return { status: status, ok: resp.ok, text: text };
                    });
                })
                .then(function(result) {
                    isSaving = false;
                    if (!result.ok) {
                        var errData;
                        try { errData = JSON.parse(result.text); } catch(e) { /* not JSON */ }
                        showAutoSaveStatus(errData && errData.message ? errData.message : 'Auto-save failed (error ' + result.status + ')', true);
                        return;
                    }
                    var data;
                    try {
                        data = JSON.parse(result.text);
                    } catch (e) {
                        showAutoSaveStatus('Auto-save failed (invalid response)', true);
                        console.error('Auto-save: invalid JSON response', result.text.substring(0, 200));
                        return;
                    }
                    if (data && data.success) {
                        lastSavedData = currentData;
                        showAutoSaveStatus('Draft auto-saved', false);
                        if (data.csrf_token) {
                            var csrfInput = form.querySelector('input[name="csrf_token"]');
                            if (csrfInput) csrfInput.value = data.csrf_token;
                        }
                    } else {
                        showAutoSaveStatus(data && data.message ? data.message : 'Auto-save failed', true);
                    }
                })
                .catch(function(err) {
                    clearTimeout(timeoutId);
                    isSaving = false;
                    var errMsg = err ? (err.message || String(err)) : 'Unknown error';
                    var isNetworkError = err && (err.name === 'AbortError' || errMsg === 'Failed to fetch' || errMsg === 'Load failed' || errMsg.indexOf('NetworkError') !== -1);
                    if (retryCount < maxRetries && isNetworkError) {
                        setTimeout(function() { performAutoSave(retryCount + 1); }, 2000 * (retryCount + 1));
                    } else if (isNetworkError) {
                        showAutoSaveStatus('Auto-save: connection issue, will retry', true);
                    } else {
                        console.error('Auto-save error:', err);
                        showAutoSaveStatus('Auto-save encountered an error', true);
                    }
                });
            }

            // Start periodic auto-save
            lastSavedData = getFormDataString();
            autoSaveTimer = setInterval(performAutoSave, AUTO_SAVE_INTERVAL);

            // Also save when user switches away from the tab
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') {
                    performAutoSave();
                }
            });

            // Save before unload as a last resort
            window.addEventListener('beforeunload', function() {
                if (getFormDataString() !== lastSavedData) {
                    performAutoSave();
                }
            });
        })();

        // Copy residential address to permanent address
        function copyResidentialToPermanent() {
            // Copy individual address components
            const residentialFields = [
                'house_no', 'street', 'subdivision', 'barangay', 'city', 'province', 'zipcode'
            ];
            
            residentialFields.forEach(field => {
                const residentialField = document.getElementById('residential_' + field);
                const permanentField = document.getElementById('permanent_' + field);
                if (residentialField && permanentField) {
                    permanentField.value = residentialField.value;
                }
            });
            
            // Copy full address textarea
            const residentialAddress = document.getElementById('residential_address');
            const permanentAddress = document.getElementById('permanent_address');
            if (residentialAddress && permanentAddress) {
                permanentAddress.value = residentialAddress.value;
            }
            
            // Copy telephone number
            const residentialTel = document.getElementById('residential_telno');
            const permanentTel = document.getElementById('permanent_telno');
            if (residentialTel && permanentTel) {
                permanentTel.value = residentialTel.value;
            }
            
            // Show success feedback
            const btn = document.getElementById('copyAddressBtn');
            if (btn) {
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-1"></i>Address Copied!';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            }
        }
    </script>
</body>
</html>