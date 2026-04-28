<?php
/**
 * Download Faculty Batch Upload Template
 * Separate file to ensure no output before headers
 */

// Suppress any errors/warnings that might output content
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define a flag to skip security headers during download
define('SKIP_SECURITY_HEADERS', true);
define('SKIP_OUTPUT_BUFFERING', true);

// CRITICAL: Disable ALL output buffering FIRST before any includes
// Must be done BEFORE session_start() and any includes
while (ob_get_level()) {
    ob_end_clean();
}

// Prevent output buffering from being started by config.php
ini_set('output_buffering', '0');

// Start session FIRST before any includes to ensure session is available
if (session_status() === PHP_SESSION_NONE) {
    // Use same session settings as config.php
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Include config.php first (defines DB constants needed by Database class)
// It will see session is already started and won't restart it
// NOTE: config.php may start output buffering, so we clear it after
require_once __DIR__ . '/../includes/config.php';

// Include functions.php for isAdmin()
require_once __DIR__ . '/../includes/functions.php';

// Include database.php (needed for Database class, uses constants from config.php)
require_once __DIR__ . '/../includes/database.php';

// CRITICAL: Clear ALL output buffers that config.php or database.php may have started
// This must be done immediately after includes to prevent any output before headers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Check authentication - session should now be active
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    // Clear buffers before redirect
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Check if user is admin (includes super_admin)
if (!isAdmin()) {
    // Clear buffers before redirect
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../login.php?error=admin_required');
    exit;
}

// Get database connection
$database = Database::getInstance();
$db = $database->getConnection();

// Check if PHPSpreadsheet is available
$phpSpreadsheetAvailable = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $phpSpreadsheetAvailable = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
}

// Database connection already established above

// Load master lists if available
$deptStmt = $db->prepare("SELECT id, name FROM departments ORDER BY name");
try { $deptStmt->execute(); $masterDepartments = $deptStmt->fetchAll(); } catch (Exception $e) { $masterDepartments = []; }

// Get positions from position_salary table
$allPositions = getAllPositions();

$empStmt = $db->prepare("SELECT id, name FROM employment_statuses ORDER BY name");
try { $empStmt->execute(); $masterEmploymentStatuses = $empStmt->fetchAll(); } catch (Exception $e) { $masterEmploymentStatuses = []; }

$campusStmt = $db->prepare("SELECT id, name FROM campuses ORDER BY name");
try { $campusStmt->execute(); $masterCampuses = $campusStmt->fetchAll(); } catch (Exception $e) { $masterCampuses = []; }

$designationStmt = $db->prepare("SELECT id, name FROM designations ORDER BY name");
try { $designationStmt->execute(); $masterDesignations = $designationStmt->fetchAll(); } catch (Exception $e) { $masterDesignations = []; }

$keyOfficialsStmt = $db->prepare("SELECT id, name FROM key_officials ORDER BY name");
try { $keyOfficialsStmt->execute(); $masterKeyOfficials = $keyOfficialsStmt->fetchAll(); } catch (Exception $e) { $masterKeyOfficials = []; }

if ($phpSpreadsheetAvailable) {
    // Excel template with enhanced design
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Faculty Data');
    
    // Prepare data for validation lists
    $departmentsList = !empty($masterDepartments) ? array_map(function($dept) { return $dept['name']; }, $masterDepartments) : [];
    $positionsList = !empty($allPositions) ? array_map(function($pos) { return $pos['position_title'] . ' (SG' . $pos['salary_grade'] . ')'; }, $allPositions) : [];
    $employmentStatusesList = !empty($masterEmploymentStatuses) ? array_map(function($es) { return $es['name']; }, $masterEmploymentStatuses) : [];
    $campusesList = !empty($masterCampuses) ? array_map(function($c) { return $c['name']; }, $masterCampuses) : [];
    $designationsList = !empty($masterDesignations) ? array_map(function($d) { return $d['name']; }, $masterDesignations) : [];
    $keyOfficialsList = !empty($masterKeyOfficials) ? array_map(function($k) { return $k['name']; }, $masterKeyOfficials) : [];
    
    // Create hidden sheet for validation lists
    $validationSheet = $spreadsheet->createSheet();
    $validationSheet->setTitle('ValidationLists');
    $validationSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
    
    // Populate validation lists
    if (!empty($departmentsList)) {
        $valRow = 1;
        foreach ($departmentsList as $dept) {
            $validationSheet->setCellValue('A' . $valRow, $dept);
            $valRow++;
        }
    }
    if (!empty($positionsList)) {
        $valRow = 1;
        foreach ($positionsList as $pos) {
            $validationSheet->setCellValue('B' . $valRow, $pos);
            $valRow++;
        }
    }
    if (!empty($employmentStatusesList)) {
        $valRow = 1;
        foreach ($employmentStatusesList as $es) {
            $validationSheet->setCellValue('C' . $valRow, $es);
            $valRow++;
        }
    }
    if (!empty($campusesList)) {
        $valRow = 1;
        foreach ($campusesList as $campus) {
            $validationSheet->setCellValue('D' . $valRow, $campus);
            $valRow++;
        }
    }
    if (!empty($designationsList)) {
        $valRow = 1;
        foreach ($designationsList as $designation) {
            $validationSheet->setCellValue('E' . $valRow, $designation);
            $valRow++;
        }
    }
    if (!empty($keyOfficialsList)) {
        $valRow = 1;
        foreach ($keyOfficialsList as $ko) {
            $validationSheet->setCellValue('F' . $valRow, $ko);
            $valRow++;
        }
    }
    
    // Set active sheet back to main sheet
    $spreadsheet->setActiveSheetIndex(0);
    $sheet = $spreadsheet->getActiveSheet();
    
    // Title row
    $sheet->setCellValue('A1', 'EMPLOYEE BATCH UPLOAD TEMPLATE');
    $sheet->mergeCells('A1:M1');
    $titleStyle = [
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C3E50']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ];
    $sheet->getStyle('A1:L1')->applyFromArray($titleStyle);
    $sheet->getRowDimension(1)->setRowHeight(30);
    
    // Instructions row
    $sheet->setCellValue('A2', 'Fill in your data starting from row 4. Required fields are marked with *. Delete example rows before uploading.');
    $sheet->mergeCells('A2:M2');
    $instructionStyle = [
        'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '666666']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F9FA']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrap' => true]
    ];
    $sheet->getStyle('A2:L2')->applyFromArray($instructionStyle);
    $sheet->getRowDimension(2)->setRowHeight(40);
    
    // Set headers
    $headers = [
        'A3' => 'First Name*',
        'B3' => 'Last Name*',
        'C3' => 'Middle Name',
        'D3' => 'Email (@wpu.edu.ph)*',
        'E3' => 'User Type*',
        'F3' => 'Gender',
        'G3' => 'Campus',
        'H3' => 'Department',
        'I3' => 'Position',
        'J3' => 'Designation',
        'K3' => 'Employment Status',
        'L3' => 'Key Official',
        'M3' => 'Hire Date'
    ];
    
    foreach ($headers as $cell => $value) {
        $sheet->setCellValue($cell, $value);
    }
    
    // Style headers
    $headerStyle = [
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '3498DB']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrap' => true],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '2980B9']]
        ]
    ];
    $sheet->getStyle('A3:M3')->applyFromArray($headerStyle);
    $sheet->getRowDimension(3)->setRowHeight(50);
    
    // Add example data rows
    $examples = [
        ['Juan', 'Dela Cruz', 'Santos', 'juan.delacruz@wpu.edu.ph', 'faculty', 'Male', 'Main Campus', 'Computer Science', 'Professor', 'Dean', 'Permanent', 'President', '2024-01-15'],
        ['Maria', 'Garcia', 'Lopez', 'maria.garcia@wpu.edu.ph', 'staff', 'Female', 'Iligan Campus', 'Admin Office', 'Admin Assistant', 'Program Chair', 'Contractual', '', '2024-02-01']
    ];
    
    $rowNum = 4;
    foreach ($examples as $example) {
        $col = 'A';
        foreach ($example as $value) {
            $sheet->setCellValue($col . $rowNum, $value);
            $col++;
        }
        $rowNum++;
    }
    
    // Style example rows
    $exampleStyle = [
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9E6']],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]
        ],
        'font' => ['color' => ['rgb' => '666666'], 'italic' => true]
    ];
    $sheet->getStyle('A4:M' . ($rowNum - 1))->applyFromArray($exampleStyle);
    
    // Add data validation for User Type (Column E)
    $validation = $sheet->getCell('E4')->getDataValidation();
    $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
    $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
    $validation->setAllowBlank(false);
    $validation->setShowInputMessage(true);
    $validation->setShowErrorMessage(true);
    $validation->setShowDropDown(true);
    $validation->setErrorTitle('Invalid User Type');
    $validation->setError('Please select either "faculty" or "staff"');
    $validation->setPromptTitle('Select User Type');
    $validation->setPrompt('Choose "faculty" for faculty members or "staff" for staff members');
    $validation->setFormula1('"faculty,staff"');
    
    // Apply validation to column E (rows 4-1000)
    for ($i = 4; $i <= 1000; $i++) {
        $sheet->getCell('E' . $i)->setDataValidation(clone $validation);
    }
    
    // Add data validation for Gender (Column F)
    $genderValidation = $sheet->getCell('F4')->getDataValidation();
    $genderValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
    $genderValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
    $genderValidation->setAllowBlank(true);
    $genderValidation->setShowInputMessage(true);
    $genderValidation->setShowErrorMessage(true);
    $genderValidation->setShowDropDown(true);
    $genderValidation->setErrorTitle('Invalid Gender');
    $genderValidation->setError('Please select a valid gender option');
    $genderValidation->setPromptTitle('Select Gender');
    $genderValidation->setPrompt('Choose a gender option (optional)');
    $genderValidation->setFormula1('"Male,Female,Other,Prefer not to say"');
    
    // Apply validation to column F (rows 4-1000)
    for ($i = 4; $i <= 1000; $i++) {
        $sheet->getCell('F' . $i)->setDataValidation(clone $genderValidation);
    }
    
    // Add data validation for Campus (Column G) if campuses exist
    if (!empty($campusesList)) {
        $campusValidation = $sheet->getCell('G4')->getDataValidation();
        $campusValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $campusValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $campusValidation->setAllowBlank(true);
        $campusValidation->setShowInputMessage(true);
        $campusValidation->setShowErrorMessage(true);
        $campusValidation->setShowDropDown(true);
        $campusValidation->setErrorTitle('Invalid Campus');
        $campusValidation->setError('Please select a valid campus from the list');
        $campusValidation->setPromptTitle('Select Campus');
        $campusValidation->setPrompt('Choose a campus from the dropdown list (optional)');
        $campusValidation->setFormula1('ValidationLists!$D$1:$D$' . count($campusesList));
        
        for ($i = 4; $i <= 1000; $i++) {
            $sheet->getCell('G' . $i)->setDataValidation(clone $campusValidation);
        }
    }
    
    // Add data validation for Department (Column H) if departments exist
    if (!empty($departmentsList)) {
        $deptValidation = $sheet->getCell('H4')->getDataValidation();
        $deptValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $deptValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $deptValidation->setAllowBlank(true);
        $deptValidation->setShowInputMessage(true);
        $deptValidation->setShowErrorMessage(true);
        $deptValidation->setShowDropDown(true);
        $deptValidation->setErrorTitle('Invalid Department');
        $deptValidation->setError('Please select a valid department from the list');
        $deptValidation->setPromptTitle('Select Department');
        $deptValidation->setPrompt('Choose a department from the dropdown list (optional)');
        $deptValidation->setFormula1('ValidationLists!$A$1:$A$' . count($departmentsList));
        
        for ($i = 4; $i <= 1000; $i++) {
            $sheet->getCell('H' . $i)->setDataValidation(clone $deptValidation);
        }
    }
    
    // Add data validation for Position (Column I) if positions exist
    if (!empty($positionsList)) {
        $posValidation = $sheet->getCell('I4')->getDataValidation();
        $posValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $posValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $posValidation->setAllowBlank(true);
        $posValidation->setShowInputMessage(true);
        $posValidation->setShowErrorMessage(true);
        $posValidation->setShowDropDown(true);
        $posValidation->setErrorTitle('Invalid Position');
        $posValidation->setError('Please select a valid position from the list');
        $posValidation->setPromptTitle('Select Position');
        $posValidation->setPrompt('Choose a position from the dropdown list (optional)');
        $posValidation->setFormula1('ValidationLists!$B$1:$B$' . count($positionsList));
        
        for ($i = 4; $i <= 1000; $i++) {
            $sheet->getCell('I' . $i)->setDataValidation(clone $posValidation);
        }
    }
    
    // Add data validation for Designation (Column J) if designations exist
    if (!empty($designationsList)) {
        $desigValidation = $sheet->getCell('J4')->getDataValidation();
        $desigValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $desigValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $desigValidation->setAllowBlank(true);
        $desigValidation->setShowInputMessage(true);
        $desigValidation->setShowErrorMessage(true);
        $desigValidation->setShowDropDown(true);
        $desigValidation->setErrorTitle('Invalid Designation');
        $desigValidation->setError('Please select a valid designation from the list');
        $desigValidation->setPromptTitle('Select Designation');
        $desigValidation->setPrompt('Choose a designation from the dropdown list (optional)');
        $desigValidation->setFormula1('ValidationLists!$E$1:$E$' . count($designationsList));
        
        for ($i = 4; $i <= 1000; $i++) {
            $sheet->getCell('J' . $i)->setDataValidation(clone $desigValidation);
        }
    }
    
    // Add data validation for Employment Status (Column K) if statuses exist
    if (!empty($employmentStatusesList)) {
        $empValidation = $sheet->getCell('K4')->getDataValidation();
        $empValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $empValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $empValidation->setAllowBlank(true);
        $empValidation->setShowInputMessage(true);
        $empValidation->setShowErrorMessage(true);
        $empValidation->setShowDropDown(true);
        $empValidation->setErrorTitle('Invalid Employment Status');
        $empValidation->setError('Please select a valid employment status from the list');
        $empValidation->setPromptTitle('Select Employment Status');
        $empValidation->setPrompt('Choose an employment status from the dropdown list (optional)');
        $empValidation->setFormula1('ValidationLists!$C$1:$C$' . count($employmentStatusesList));
        
        for ($i = 4; $i <= 1000; $i++) {
            $sheet->getCell('K' . $i)->setDataValidation(clone $empValidation);
        }
    }
    
    // Add data validation for Key Official (Column L) if key officials exist
    if (!empty($keyOfficialsList)) {
        $koValidation = $sheet->getCell('L4')->getDataValidation();
        $koValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $koValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $koValidation->setAllowBlank(true);
        $koValidation->setShowInputMessage(true);
        $koValidation->setShowErrorMessage(true);
        $koValidation->setShowDropDown(true);
        $koValidation->setErrorTitle('Invalid Key Official');
        $koValidation->setError('Please select a valid key official from the list');
        $koValidation->setPromptTitle('Select Key Official');
        $koValidation->setPrompt('Choose a key official from the dropdown list (optional)');
        $koValidation->setFormula1('ValidationLists!$F$1:$F$' . count($keyOfficialsList));
        
        for ($i = 4; $i <= 1000; $i++) {
            $sheet->getCell('L' . $i)->setDataValidation(clone $koValidation);
        }
    }
    
    // Set column widths
    $sheet->getColumnDimension('A')->setWidth(18); // First Name
    $sheet->getColumnDimension('B')->setWidth(18); // Last Name
    $sheet->getColumnDimension('C')->setWidth(18); // Middle Name
    $sheet->getColumnDimension('D')->setWidth(30); // Email
    $sheet->getColumnDimension('E')->setWidth(15); // User Type
    $sheet->getColumnDimension('F')->setWidth(15); // Gender
    $sheet->getColumnDimension('G')->setWidth(20); // Campus
    $sheet->getColumnDimension('H')->setWidth(25); // Department
    $sheet->getColumnDimension('I')->setWidth(30); // Position
    $sheet->getColumnDimension('J')->setWidth(20); // Designation
    $sheet->getColumnDimension('K')->setWidth(20); // Employment Status
    $sheet->getColumnDimension('L')->setWidth(18); // Key Official
    $sheet->getColumnDimension('M')->setWidth(15); // Hire Date
    
    // Freeze panes (freeze first 3 rows)
    $sheet->freezePane('A4');
    
    // Add reference data sheet
    $refSheet = $spreadsheet->createSheet();
    $refSheet->setTitle('Reference Data');
    
    // Title
    $refSheet->setCellValue('A1', 'REFERENCE DATA');
    $refSheet->mergeCells('A1:C1');
    $refSheet->getStyle('A1')->applyFromArray($titleStyle);
    $refSheet->getRowDimension(1)->setRowHeight(30);
    
    // Column headers
    $refSheet->setCellValue('A2', 'Available Departments');
    $refSheet->setCellValue('B2', 'Available Positions');
    $refSheet->setCellValue('C2', 'Available Employment Statuses');
    $refSheet->getStyle('A2:C2')->applyFromArray($headerStyle);
    
    // Add departments
    $rowNum = 3;
    foreach ($masterDepartments as $dept) {
        $refSheet->setCellValue('A' . $rowNum, $dept['name']);
        $rowNum++;
    }
    
    // Positions
    $rowNum = 3;
    foreach ($allPositions as $pos) {
        $refSheet->setCellValue('B' . $rowNum, $pos['position_title'] . ' (SG' . $pos['salary_grade'] . ')');
        $rowNum++;
    }
    
    // Employment statuses
    $rowNum = 3;
    foreach ($masterEmploymentStatuses as $es) {
        $refSheet->setCellValue('C' . $rowNum, $es['name']);
        $rowNum++;
    }
    
    // Style reference data
    $refSheet->getStyle('A3:C100')->applyFromArray([
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]
        ],
        'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ]);
    
    // Auto-size reference columns
    $refSheet->getColumnDimension('A')->setAutoSize(true);
    $refSheet->getColumnDimension('B')->setAutoSize(true);
    $refSheet->getColumnDimension('C')->setAutoSize(true);
    
    // Add instructions sheet
    $instructionsSheet = $spreadsheet->createSheet();
    $instructionsSheet->setTitle('Instructions');
    
    $instructions = [
        ['FACULTY BATCH UPLOAD - INSTRUCTIONS', '', ''],
        ['', '', ''],
        ['STEP 1: Fill in the Data', '', ''],
        ['- Open the "Faculty Data" sheet', '', ''],
        ['- Fill in your data starting from row 4', '', ''],
        ['- Delete the example rows (rows 4-5) before uploading', '', ''],
        ['', '', ''],
        ['STEP 2: Required Fields', '', ''],
        ['- First Name* (Required)', '', ''],
        ['- Last Name* (Required)', '', ''],
        ['- Email* (Required - must end with @wpu.edu.ph)', '', ''],
        ['- User Type* (Required - select from dropdown: faculty or staff)', '', ''],
        ['', '', ''],
        ['STEP 3: Optional Fields', '', ''],
        ['- Middle Name (Optional)', '', ''],
        ['- Gender (Optional - use dropdown to select)', '', ''],
        ['- Campus (Optional)', '', ''],
        ['- Department (Optional - use dropdown to select)', '', ''],
        ['- Position (Optional - use dropdown to select)', '', ''],
        ['- Designation (Optional - use dropdown to select)', '', ''],
        ['- Employment Status (Optional - use dropdown to select)', '', ''],
        ['- Key Official (Optional - use dropdown to select)', '', ''],
        ['- Hire Date (Optional - format: YYYY-MM-DD)', '', ''],
        ['', '', ''],
        ['STEP 4: Data Validation', '', ''],
        ['- Use the dropdown arrows in columns E, F, H, I, J, K, L to select values', '', ''],
        ['- Hire Date should be in YYYY-MM-DD format (e.g., 2024-01-15)', '', ''],
        ['- This ensures data consistency and prevents errors', '', ''],
        ['- You can also type values manually if needed', '', ''],
        ['', '', ''],
        ['STEP 5: Upload', '', ''],
        ['- Save your completed file', '', ''],
        ['- Go to the Batch Upload section in the admin panel', '', ''],
        ['- Select your file and click Upload', '', ''],
        ['- Review the results and check for any errors', '', ''],
        ['', '', ''],
        ['IMPORTANT NOTES:', '', ''],
        ['- Each email can only be used once per user type', '', ''],
        ['- Email addresses must end with @wpu.edu.ph', '', ''],
        ['- User Type must be exactly "faculty" or "staff" (case-insensitive)', '', ''],
        ['- Accounts will be created automatically with generated passwords', '', ''],
        ['- Account creation emails will be sent to each user', '', ''],
        ['', '', ''],
        ['NEED HELP?', '', ''],
        ['- Check the "Reference Data" sheet for available options', '', ''],
        ['- Contact your system administrator if you encounter issues', '', '']
    ];
    
    $rowNum = 1;
    foreach ($instructions as $instruction) {
        $col = 'A';
        foreach ($instruction as $value) {
            $instructionsSheet->setCellValue($col . $rowNum, $value);
            $col++;
        }
        $rowNum++;
    }
    
    // Style instructions
    $instructionsSheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C3E50']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
    ]);
    $instructionsSheet->mergeCells('A1:C1');
    
    $instructionsSheet->getStyle('A3')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '2C3E50']]
    ]);
    $instructionsSheet->getStyle('A9')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '2C3E50']]
    ]);
    $instructionsSheet->getStyle('A15')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '2C3E50']]
    ]);
    $instructionsSheet->getStyle('A21')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '2C3E50']]
    ]);
    $instructionsSheet->getStyle('A27')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '2C3E50']]
    ]);
    $instructionsSheet->getStyle('A33')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'E74C3C']]
    ]);
    $instructionsSheet->getStyle('A41')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '3498DB']]
    ]);
    
    $instructionsSheet->getColumnDimension('A')->setWidth(60);
    $instructionsSheet->getColumnDimension('B')->setWidth(5);
    $instructionsSheet->getColumnDimension('C')->setWidth(5);
    
    // Set active sheet back to main sheet
    $spreadsheet->setActiveSheetIndex(0);
    
    // Create temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'faculty_template_');
    if ($tempFile === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('Error: Failed to create temporary file.');
    }
    
    try {
        // Write spreadsheet to temporary file with improved error handling
        $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // Set writer options for better compatibility
        $writer->setPreCalculateFormulas(false); // Don't pre-calculate formulas (improves performance)
        
        // Save the file
        $writer->save($tempFile);
        
        // Verify the file was created and has content
        if (!file_exists($tempFile)) {
            throw new Exception('Failed to create temporary Excel file.');
        }
        
        // Get file size
        $fileSize = filesize($tempFile);
        if ($fileSize === false || $fileSize === 0) {
            @unlink($tempFile);
            throw new Exception('Generated Excel file is empty or invalid.');
        }
        
        // Clean up spreadsheet resources before sending file
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        unset($writer);
        
        // Clear any remaining output buffers completely
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Close session before sending file (prevents session lock issues)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // Ensure no output has been sent
        if (headers_sent($file, $line)) {
            @unlink($tempFile);
            http_response_code(500);
            die("Headers already sent in $file on line $line");
        }
        
        // Set headers FIRST before any output
        // Remove any existing output buffers and headers
        http_response_code(200);
        
        // Generate filename with timestamp to prevent caching issues
        $timestamp = date('Ymd_His');
        $filename = 'faculty_batch_upload_template_' . $timestamp . '.xlsx';
        
        // Send all headers to force download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="faculty_batch_upload_template.xlsx"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT'); // Set to past date
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff'); // Prevent MIME type sniffing
        header('Accept-Ranges: bytes'); // Allow byte-range requests
        
        // Ensure headers are sent immediately - no buffering at this point
        // All buffers should already be cleared above
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        
        // Use readfile() for proper binary file output
        $readResult = @readfile($tempFile);
        if ($readResult === false || $readResult !== $fileSize) {
            @unlink($tempFile);
            http_response_code(500);
            die('Error: Failed to read file for download.');
        }
        
        // Clean up temporary file
        @unlink($tempFile);
        
        // Exit immediately after output
        exit;
    } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
        // Clean up spreadsheet resources
        if (isset($spreadsheet)) {
            try {
                $spreadsheet->disconnectWorksheets();
            } catch (Exception $cleanupEx) {
                // Ignore cleanup errors
            }
            unset($spreadsheet);
        }
        if (isset($writer)) {
            unset($writer);
        }
        
        // Clean up temp file if it exists
        if (isset($tempFile) && file_exists($tempFile)) {
            @unlink($tempFile);
        }
        
        // Clear buffers before error output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('Error generating Excel file: ' . $e->getMessage());
    } catch (Exception $e) {
        // Clean up spreadsheet resources
        if (isset($spreadsheet)) {
            try {
                $spreadsheet->disconnectWorksheets();
            } catch (Exception $cleanupEx) {
                // Ignore cleanup errors
            }
            unset($spreadsheet);
        }
        if (isset($writer)) {
            unset($writer);
        }
        
        // Clean up temp file if it exists
        if (isset($tempFile) && file_exists($tempFile)) {
            @unlink($tempFile);
        }
        
        // Clear buffers before error output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('Error generating Excel file: ' . $e->getMessage());
    }
} else {
    // CSV template (fallback)
    // Clear any remaining output buffers completely
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Close session before sending file (prevents session lock issues)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // Ensure no output has been sent
    if (headers_sent($file, $line)) {
        http_response_code(500);
        die("Headers already sent in $file on line $line");
    }
    
    // Set headers - must be sent before any output
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="faculty_batch_upload_template.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Expires: 0');
    header('Content-Transfer-Encoding: binary');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 to help Excel recognize encoding
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, [
        'First Name*',
        'Last Name*',
        'Middle Name',
        'Email (@wpu.edu.ph)*',
        'User Type* (faculty/staff)',
        'Gender',
        'Campus',
        'Department',
        'Position',
        'Designation',
        'Employment Status',
        'Key Official',
        'Hire Date'
    ]);
    
    // Example data
    fputcsv($output, [
        'Juan',
        'Dela Cruz',
        'Santos',
        'juan.delacruz@wpu.edu.ph',
        'faculty',
        'Male',
        'Main Campus',
        'Computer Science',
        'Professor',
        'Dean',
        'Permanent',
        'President',
        '2024-01-15'
    ]);
    
    fclose($output);
    exit;
}

