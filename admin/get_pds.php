<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

header('Content-Type: application/json');

$pdsId = (int)($_GET['id'] ?? 0);

if (!$pdsId) {
    echo json_encode(['success' => false, 'message' => 'Invalid PDS ID']);
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        SELECT p.*, u.first_name, u.last_name, u.email,
               fp.employee_id as profile_employee_id
        FROM faculty_pds p
        JOIN users u ON p.faculty_id = u.id
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
        WHERE p.id = ?
    ");
    $stmt->execute([$pdsId]);
    $pds = $stmt->fetch();
    
    if ($pds) {
        // Ensure agency_employee_no is set (use employee_id from faculty_profiles as fallback)
        if (empty($pds['agency_employee_no']) && !empty($pds['profile_employee_id'])) {
            $pds['agency_employee_no'] = $pds['profile_employee_id'];
        }
        // Fetch normalized child rows
        $children = $db->prepare("SELECT id, name, dob FROM pds_children WHERE pds_id = ? ORDER BY id");
        $children->execute([$pdsId]);
        $pds['children'] = $children->fetchAll();

        $education = $db->prepare("SELECT id, level, school, degree, from_date, to_date, units_earned, year_graduated, academic_honors FROM pds_education WHERE pds_id = ? ORDER BY id");
        $education->execute([$pdsId]);
        $pds['education'] = $education->fetchAll();

        // Fetch civil service eligibility
        $eligibility = $db->prepare("SELECT id, title, rating, date_of_exam, place_of_exam, license_number, date_of_validity FROM faculty_civil_service_eligibility WHERE pds_id = ? ORDER BY id");
        $eligibility->execute([$pdsId]);
        $pds['eligibility'] = $eligibility->fetchAll();

        $experience = $db->prepare("SELECT id, dates, position, company, salary, salary_grade, employment_status, appointment_status, gov_service FROM pds_experience WHERE pds_id = ? ORDER BY id");
        $experience->execute([$pdsId]);
        $pds['experience'] = $experience->fetchAll();

        $voluntary = $db->prepare("SELECT id, org, dates, hours, position FROM pds_voluntary WHERE pds_id = ? ORDER BY id");
        $voluntary->execute([$pdsId]);
        $pds['voluntary'] = $voluntary->fetchAll();

        $learning = $db->prepare("SELECT id, title, dates, hours, type, conducted_by, has_certificate, venue, certificate_details FROM pds_learning WHERE pds_id = ? ORDER BY id");
        $learning->execute([$pdsId]);
        $pds['learning'] = $learning->fetchAll();

        $references = $db->prepare("SELECT id, name, address, phone FROM pds_references WHERE pds_id = ? ORDER BY id");
        $references->execute([$pdsId]);
        $pds['references'] = $references->fetchAll();

        // Decode additional_questions JSON into an associative array for the front-end
        if (!empty($pds['additional_questions'])) {
            $decoded = json_decode($pds['additional_questions'], true);
            $pds['additional_questions'] = $decoded ?: [];
        } else {
            $pds['additional_questions'] = [];
        }

        // Decode other_info JSON for skills, distinctions, memberships
        if (!empty($pds['other_info'])) {
            $decoded = json_decode($pds['other_info'], true);
            $pds['other_info'] = $decoded ?: [];
            
            // Extract additional fields from other_info and add them to $pds for display
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
            // agency_employee_id: use column if present, else fallback to other_info (pre-migration)
            if (empty($pds['agency_employee_id']) && isset($pds['other_info']['agency_employee_id'])) {
                $pds['agency_employee_id'] = $pds['other_info']['agency_employee_id'];
            }
        } else {
            $pds['other_info'] = [];
        }
        echo json_encode(['success' => true, 'pds' => $pds]);
    } else {
        echo json_encode(['success' => false, 'message' => 'PDS not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>






