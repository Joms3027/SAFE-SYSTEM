<?php
/**
 * Auto-Save Handler for PDS Form
 * Saves form data as draft without validation using individual table columns.
 * Called via AJAX from the PDS form to periodically persist user input.
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');

function autoSaveJsonResponse($data) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['success' => false, 'message' => 'JSON encoding error']);
    }
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    exit;
}

try {
    require_once '../includes/config.php';
    require_once '../includes/database.php';
    require_once '../includes/functions.php';
} catch (Throwable $e) {
    error_log("Auto-save init error: " . $e->getMessage());
    autoSaveJsonResponse(['success' => false, 'message' => 'Server error. Please try again.']);
}

if (!isLoggedIn() || !isFaculty()) {
    http_response_code(401);
    autoSaveJsonResponse(['success' => false, 'message' => 'Unauthorized']);
}

$facultyId = $_SESSION['user_id'];
$database = Database::getInstance();
$db = $database->getConnection();

try {
    $formData = $_POST;
    unset($formData['csrf_token']);
    unset($formData['action']);

    // Build residential address from component parts
    $residentialAddressParts = [];
    if (!empty($formData['residential_house_no'])) $residentialAddressParts[] = $formData['residential_house_no'];
    if (!empty($formData['residential_street'])) $residentialAddressParts[] = $formData['residential_street'];
    if (!empty($formData['residential_subdivision'])) $residentialAddressParts[] = $formData['residential_subdivision'];
    if (!empty($formData['residential_barangay'])) $residentialAddressParts[] = $formData['residential_barangay'];
    if (!empty($formData['residential_city'])) $residentialAddressParts[] = $formData['residential_city'];
    if (!empty($formData['residential_province'])) $residentialAddressParts[] = $formData['residential_province'];
    $residentialAddress = !empty($formData['residential_address'])
        ? $formData['residential_address']
        : (!empty($residentialAddressParts) ? implode(', ', $residentialAddressParts) : '');

    // Build permanent address from component parts
    $permanentAddressParts = [];
    if (!empty($formData['permanent_house_no'])) $permanentAddressParts[] = $formData['permanent_house_no'];
    if (!empty($formData['permanent_street'])) $permanentAddressParts[] = $formData['permanent_street'];
    if (!empty($formData['permanent_subdivision'])) $permanentAddressParts[] = $formData['permanent_subdivision'];
    if (!empty($formData['permanent_barangay'])) $permanentAddressParts[] = $formData['permanent_barangay'];
    if (!empty($formData['permanent_city'])) $permanentAddressParts[] = $formData['permanent_city'];
    if (!empty($formData['permanent_province'])) $permanentAddressParts[] = $formData['permanent_province'];
    $permanentAddress = !empty($formData['permanent_address'])
        ? $formData['permanent_address']
        : (!empty($permanentAddressParts) ? implode(', ', $permanentAddressParts) : '');

    // Prepare other_info JSON for fields not in the main table
    $otherInfo = $formData['other'] ?? [];
    foreach (['dual_citizenship_country', 'umid_id', 'philsys_number',
              'residential_house_no', 'residential_street', 'residential_subdivision',
              'residential_barangay', 'residential_city', 'residential_province',
              'permanent_house_no', 'permanent_street', 'permanent_subdivision',
              'permanent_barangay', 'permanent_city', 'permanent_province',
              'spouse_name_extension', 'sworn_date',
              'government_id_number', 'government_id_issue_date', 'government_id_issue_place'] as $field) {
        if (isset($formData[$field])) {
            $otherInfo[$field] = $formData[$field];
        }
    }

    // Build additional_questions JSON
    $additionalQuestions = $formData['additional_questions'] ?? [];

    // Numeric conversions
    $height = !empty($formData['height']) && is_numeric($formData['height']) ? floatval($formData['height']) : null;
    $weight = !empty($formData['weight']) && is_numeric($formData['weight']) ? floatval($formData['weight']) : null;

    $dateOfBirth = !empty($formData['date_of_birth']) ? $formData['date_of_birth'] : null;
    $dateAccomplished = !empty($formData['date_accomplished']) ? $formData['date_accomplished'] : null;
    if ($dateAccomplished && strpos($dateAccomplished, ' ') === false) {
        $dateAccomplished .= ' 00:00:00';
    }

    $pdsData = [
        'faculty_id'               => $facultyId,
        'last_name'                => trim($formData['last_name'] ?? '') ?: null,
        'first_name'               => trim($formData['first_name'] ?? '') ?: null,
        'middle_name'              => trim($formData['middle_name'] ?? '') ?: null,
        'name_extension'           => trim($formData['name_extension'] ?? '') ?: null,
        'date_of_birth'            => $dateOfBirth,
        'place_of_birth'           => trim($formData['place_of_birth'] ?? '') ?: null,
        'sex'                      => !empty($formData['sex']) ? $formData['sex'] : null,
        'civil_status'             => !empty($formData['civil_status']) ? $formData['civil_status'] : null,
        'height'                   => $height,
        'weight'                   => $weight,
        'blood_type'               => trim($formData['blood_type'] ?? '') ?: null,
        'citizenship'              => trim($formData['citizenship'] ?? 'Filipino'),
        'gsis_id'                  => trim($formData['gsis_id'] ?? '') ?: null,
        'pagibig_id'               => trim($formData['pagibig_id'] ?? '') ?: null,
        'philhealth_id'            => trim($formData['philhealth_id'] ?? '') ?: null,
        'sss_id'                   => trim($formData['sss_id'] ?? '') ?: null,
        'tin'                      => trim($formData['tin'] ?? '') ?: null,
        'agency_employee_no'       => trim($formData['agency_employee_no'] ?? '') ?: null,
        'agency_employee_id'       => trim($formData['agency_employee_id'] ?? '') ?: null,
        'residential_address'      => $residentialAddress ?: null,
        'residential_zipcode'      => trim($formData['residential_zipcode'] ?? '') ?: null,
        'residential_telno'        => trim($formData['residential_telno'] ?? '') ?: null,
        'permanent_address'        => $permanentAddress ?: null,
        'permanent_zipcode'        => trim($formData['permanent_zipcode'] ?? '') ?: null,
        'permanent_telno'          => trim($formData['permanent_telno'] ?? '') ?: null,
        'email'                    => trim($formData['email'] ?? '') ?: null,
        'mobile_no'                => trim($formData['mobile_no'] ?? '') ?: null,
        'email_alt'                => trim($formData['email_alt'] ?? '') ?: null,
        'mobile_no_alt'            => trim($formData['mobile_no_alt'] ?? '') ?: null,
        'spouse_last_name'         => trim($formData['spouse_last_name'] ?? '') ?: null,
        'spouse_first_name'        => trim($formData['spouse_first_name'] ?? '') ?: null,
        'spouse_middle_name'       => trim($formData['spouse_middle_name'] ?? '') ?: null,
        'spouse_occupation'        => trim($formData['spouse_occupation'] ?? '') ?: null,
        'spouse_employer'          => trim($formData['spouse_employer'] ?? '') ?: null,
        'spouse_business_address'  => trim($formData['spouse_business_address'] ?? '') ?: null,
        'spouse_telno'             => trim($formData['spouse_telno'] ?? '') ?: null,
        'father_last_name'         => trim($formData['father_last_name'] ?? '') ?: null,
        'father_first_name'        => trim($formData['father_first_name'] ?? '') ?: null,
        'father_middle_name'       => trim($formData['father_middle_name'] ?? '') ?: null,
        'father_name_extension'    => trim($formData['father_name_extension'] ?? '') ?: null,
        'mother_last_name'         => trim($formData['mother_last_name'] ?? '') ?: null,
        'mother_first_name'        => trim($formData['mother_first_name'] ?? '') ?: null,
        'mother_middle_name'       => trim($formData['mother_middle_name'] ?? '') ?: null,
        'children_info'            => json_encode($formData['children'] ?? []),
        'educational_background'   => json_encode($formData['education'] ?? []),
        'civil_service_eligibility'=> json_encode($formData['eligibility'] ?? []),
        'work_experience'          => json_encode($formData['experience'] ?? []),
        'voluntary_work'           => json_encode($formData['voluntary'] ?? []),
        'learning_development'     => json_encode($formData['learning'] ?? []),
        'other_info'               => json_encode($otherInfo),
        'additional_questions'     => json_encode($additionalQuestions),
        'position'                 => trim($formData['position'] ?? '') ?: null,
        'date_accomplished'        => $dateAccomplished,
    ];

    $db->beginTransaction();

    // Look for an existing editable PDS (draft OR rejected)
    $stmt = $db->prepare("SELECT id, status FROM faculty_pds WHERE faculty_id = ? AND status IN ('draft','rejected') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$facultyId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $updateFields = [];
        $updateValues = [];
        foreach ($pdsData as $key => $value) {
            $updateFields[] = "`$key` = ?";
            $updateValues[] = $value;
        }
        $updateValues[] = $existing['id'];
        $stmt = $db->prepare("UPDATE faculty_pds SET " . implode(', ', $updateFields) . " WHERE id = ?");
        $stmt->execute($updateValues);
        $pdsId = $existing['id'];
    } else {
        // Only create new draft if there is no submitted/approved PDS blocking it
        $pdsData['status'] = 'draft';
        $insertFields = array_keys($pdsData);
        $placeholders = str_repeat('?,', count($pdsData) - 1) . '?';
        $stmt = $db->prepare("INSERT INTO faculty_pds (`" . implode('`, `', $insertFields) . "`) VALUES ($placeholders)");
        $stmt->execute(array_values($pdsData));
        $pdsId = $db->lastInsertId();
    }

    // Sync normalized child tables
    // Children
    $db->prepare("DELETE FROM pds_children WHERE pds_id = ?")->execute([$pdsId]);
    if (!empty($formData['children']) && is_array($formData['children'])) {
        $ins = $db->prepare("INSERT INTO pds_children (pds_id, name, dob) VALUES (?, ?, ?)");
        foreach ($formData['children'] as $c) {
            $name = trim($c['name'] ?? '');
            $dob  = !empty($c['dob']) ? $c['dob'] : null;
            if ($name === '' && $dob === null) continue;
            $ins->execute([$pdsId, $name, $dob]);
        }
    }

    // Education
    $db->prepare("DELETE FROM pds_education WHERE pds_id = ?")->execute([$pdsId]);
    if (!empty($formData['education']) && is_array($formData['education'])) {
        $ins = $db->prepare("INSERT INTO pds_education (pds_id, level, school, degree, from_date, to_date, units_earned, year_graduated, academic_honors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($formData['education'] as $e) {
            $level = trim($e['level'] ?? '');
            $school = trim($e['school'] ?? '');
            $degree = trim($e['degree'] ?? '');
            if ($level === '' && $school === '' && $degree === '') continue;
            $ins->execute([$pdsId, $level, $school, $degree, trim($e['from_date'] ?? '') ?: null, trim($e['to_date'] ?? '') ?: null, trim($e['units_earned'] ?? '') ?: null, trim($e['year_graduated'] ?? '') ?: null, trim($e['academic_honors'] ?? '') ?: null]);
        }
    }

    // Civil Service Eligibility
    $db->prepare("DELETE FROM faculty_civil_service_eligibility WHERE pds_id = ?")->execute([$pdsId]);
    if (!empty($formData['eligibility']) && is_array($formData['eligibility'])) {
        $ins = $db->prepare("INSERT INTO faculty_civil_service_eligibility (pds_id, title, rating, date_of_exam, place_of_exam, license_number, date_of_validity) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($formData['eligibility'] as $el) {
            $title = trim($el['title'] ?? '');
            if ($title === '' && trim($el['rating'] ?? '') === '' && trim($el['date_of_exam'] ?? '') === '') continue;
            $ins->execute([$pdsId, $title ?: null, trim($el['rating'] ?? '') ?: null, trim($el['date_of_exam'] ?? '') ?: null, trim($el['place_of_exam'] ?? '') ?: null, trim($el['license_number'] ?? '') ?: null, trim($el['date_of_validity'] ?? '') ?: null]);
        }
    }

    // Experience
    $db->prepare("DELETE FROM pds_experience WHERE pds_id = ?")->execute([$pdsId]);
    if (!empty($formData['experience']) && is_array($formData['experience'])) {
        $ins = $db->prepare("INSERT INTO pds_experience (pds_id, dates, position, company, salary, salary_grade, employment_status, appointment_status, gov_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($formData['experience'] as $ex) {
            $dates = trim($ex['dates'] ?? '');
            $position = trim($ex['position'] ?? '');
            $company = trim($ex['company'] ?? '');
            if ($dates === '' && $position === '' && $company === '') continue;
            $ins->execute([$pdsId, $dates, $position, $company, trim($ex['salary'] ?? ''), trim($ex['salary_grade'] ?? '') ?: null, trim($ex['employment_status'] ?? '') ?: null, trim($ex['appointment_status'] ?? '') ?: null, trim($ex['gov_service'] ?? '') ?: null]);
        }
    }

    // Voluntary
    $db->prepare("DELETE FROM pds_voluntary WHERE pds_id = ?")->execute([$pdsId]);
    if (!empty($formData['voluntary']) && is_array($formData['voluntary'])) {
        $ins = $db->prepare("INSERT INTO pds_voluntary (pds_id, org, dates, hours, position) VALUES (?, ?, ?, ?, ?)");
        foreach ($formData['voluntary'] as $v) {
            $org = trim($v['org'] ?? '');
            if ($org === '' && trim($v['dates'] ?? '') === '') continue;
            $ins->execute([$pdsId, $org, trim($v['dates'] ?? ''), trim($v['hours'] ?? '') ?: null, trim($v['position'] ?? '') ?: null]);
        }
    }

    // Learning
    $db->prepare("DELETE FROM pds_learning WHERE pds_id = ?")->execute([$pdsId]);
    if (!empty($formData['learning']) && is_array($formData['learning'])) {
        $ins = $db->prepare("INSERT INTO pds_learning (pds_id, title, dates, hours, type, conducted_by, has_certificate, venue, certificate_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($formData['learning'] as $l) {
            $title = trim($l['title'] ?? '');
            if ($title === '' && trim($l['dates'] ?? '') === '') continue;
            $ins->execute([$pdsId, $title, trim($l['dates'] ?? ''), trim($l['hours'] ?? '') ?: null, trim($l['type'] ?? '') ?: null, trim($l['conducted_by'] ?? '') ?: null, trim($l['has_certificate'] ?? '') ?: null, trim($l['venue'] ?? '') ?: null, trim($l['certificate_details'] ?? '') ?: null]);
        }
    }

    // References
    $db->prepare("DELETE FROM pds_references WHERE pds_id = ?")->execute([$pdsId]);
    $refs = $formData['other']['references'] ?? [];
    if (!empty($refs) && is_array($refs)) {
        $ins = $db->prepare("INSERT INTO pds_references (pds_id, name, address, phone) VALUES (?, ?, ?, ?)");
        foreach ($refs as $r) {
            $name = trim($r['name'] ?? '');
            if ($name === '' && trim($r['address'] ?? '') === '' && trim($r['phone'] ?? '') === '') continue;
            $ins->execute([$pdsId, $name, trim($r['address'] ?? ''), trim($r['phone'] ?? '')]);
        }
    }

    $db->commit();

    // Return a fresh CSRF token so subsequent saves/submits still work
    $newToken = '';
    if (function_exists('generateFormToken')) {
        $newToken = generateFormToken();
    }

    autoSaveJsonResponse([
        'success'   => true,
        'message'   => 'Draft saved automatically',
        'pds_id'    => $pdsId,
        'csrf_token'=> $newToken,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Auto-save error: " . $e->getMessage());
    http_response_code(500);
    autoSaveJsonResponse([
        'success' => false,
        'message' => 'Error saving draft. Please try again.'
    ]);
}
?>
