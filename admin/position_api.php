<?php
// Suppress any output before JSON
ob_start();

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

// Clear any output
ob_clean();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

// Check if step column exists
$hasStepColumn = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM position_salary LIKE 'step'");
    $hasStepColumn = $colCheck && $colCheck->rowCount() > 0;
} catch (Exception $e) {
    // Ignore
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $positionTitle = trim($_POST['position_title']);
            $salaryGrade = (int)$_POST['salary_grade'];
            $step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
            $annualSalary = (float)$_POST['annual_salary'];
            
            // Validate inputs
            if (empty($positionTitle)) {
                throw new Exception('Position title is required.');
            }
            
            if ($salaryGrade < 0 || $salaryGrade > 33) {
                throw new Exception('Salary grade must be between 0 and 33.');
            }
            
            if ($hasStepColumn && ($step < 1 || $step > 8)) {
                throw new Exception('Step must be between 1 and 8.');
            }
            
            if ($annualSalary <= 0) {
                throw new Exception('Annual salary must be greater than 0.');
            }
            
            // Convert annual salary to monthly (database stores monthly)
            $monthlySalary = $annualSalary / 12;
            
            // Insert new position
            if ($hasStepColumn) {
                $stmt = $db->prepare("INSERT INTO position_salary (position_title, salary_grade, step, annual_salary) VALUES (?, ?, ?, ?)");
                $ok = $stmt->execute([$positionTitle, $salaryGrade, $step, $monthlySalary]);
            } else {
                $stmt = $db->prepare("INSERT INTO position_salary (position_title, salary_grade, annual_salary) VALUES (?, ?, ?)");
                $ok = $stmt->execute([$positionTitle, $salaryGrade, $monthlySalary]);
            }
            
            if ($ok) {
                logAction('POSITION_CREATE', "Created position: $positionTitle");
                echo json_encode([
                    'success' => true,
                    'message' => 'Position added successfully!'
                ]);
            } else {
                throw new Exception('Failed to add position.');
            }
            break;
            
        case 'update':
            $positionId = (int)$_POST['position_id'];
            $positionTitle = trim($_POST['position_title']);
            $salaryGrade = (int)$_POST['salary_grade'];
            $step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
            $annualSalary = (float)$_POST['annual_salary'];
            
            // Validate inputs
            if (empty($positionTitle)) {
                throw new Exception('Position title is required.');
            }
            
            if ($salaryGrade < 0 || $salaryGrade > 33) {
                throw new Exception('Salary grade must be between 0 and 33.');
            }
            
            if ($hasStepColumn && ($step < 1 || $step > 8)) {
                throw new Exception('Step must be between 1 and 8.');
            }
            
            if ($annualSalary <= 0) {
                throw new Exception('Annual salary must be greater than 0.');
            }
            
            // Convert annual salary to monthly (database stores monthly)
            $monthlySalary = $annualSalary / 12;
            
            // Update position
            if ($hasStepColumn) {
                $stmt = $db->prepare("UPDATE position_salary SET position_title = ?, salary_grade = ?, step = ?, annual_salary = ? WHERE id = ?");
                $ok = $stmt->execute([$positionTitle, $salaryGrade, $step, $monthlySalary, $positionId]);
            } else {
                $stmt = $db->prepare("UPDATE position_salary SET position_title = ?, salary_grade = ?, annual_salary = ? WHERE id = ?");
                $ok = $stmt->execute([$positionTitle, $salaryGrade, $monthlySalary, $positionId]);
            }
            
            if ($ok) {
                logAction('POSITION_UPDATE', "Updated position ID: $positionId");
                echo json_encode([
                    'success' => true,
                    'message' => 'Position updated successfully!'
                ]);
            } else {
                throw new Exception('Failed to update position.');
            }
            break;
            
        case 'delete':
            $positionId = (int)$_POST['position_id'];
            
            // Check if position exists
            $stmt = $db->prepare("SELECT position_title FROM position_salary WHERE id = ?");
            $stmt->execute([$positionId]);
            $position = $stmt->fetch();
            
            if (!$position) {
                throw new Exception('Position not found.');
            }
            
            // Delete position
            $stmt = $db->prepare("DELETE FROM position_salary WHERE id = ?");
            
            if ($stmt->execute([$positionId])) {
                logAction('POSITION_DELETE', "Deleted position: {$position['position_title']}");
                echo json_encode([
                    'success' => true,
                    'message' => 'Position deleted successfully!'
                ]);
            } else {
                throw new Exception('Failed to delete position.');
            }
            break;
            
        case 'fetch':
            try {
                // Fetch filtered positions for AJAX
                $search = $_GET['search'] ?? '';
                $salaryGradeFilter = $_GET['salary_grade'] ?? '';
                $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                $perPage = 10;
                $offset = ($page - 1) * $perPage;
                
                $whereClause = "1=1";
                $params = [];
                
                if ($search) {
                    $whereClause .= " AND ps1.position_title LIKE ?";
                    $params[] = "%$search%";
                }
                
                if ($salaryGradeFilter) {
                    $whereClause .= " AND ps1.salary_grade = ?";
                    $params[] = $salaryGradeFilter;
                }
                
                // Get total count for pagination
                if ($hasStepColumn) {
                    $countSql = "SELECT COUNT(*) as total FROM (
                                 SELECT 1 FROM position_salary ps1
                                 INNER JOIN (
                                     SELECT position_title, salary_grade, COALESCE(step, 1) as step, MIN(id) as min_id
                                     FROM position_salary
                                     GROUP BY position_title, salary_grade, COALESCE(step, 1)
                                 ) ps2 ON ps1.position_title = ps2.position_title AND ps1.salary_grade = ps2.salary_grade AND COALESCE(ps1.step, 1) = ps2.step AND ps1.id = ps2.min_id
                                 WHERE 1=1";
                } else {
                    $countSql = "SELECT COUNT(DISTINCT position_title) as total FROM position_salary WHERE 1=1";
                }
                $countParams = [];
                if ($search) {
                    $countSql .= " AND ps1.position_title LIKE ?";
                    $countParams[] = "%$search%";
                }
                if ($salaryGradeFilter) {
                    $countSql .= " AND ps1.salary_grade = ?";
                    $countParams[] = $salaryGradeFilter;
                }
                if ($hasStepColumn) $countSql .= ") sub";
                $countStmt = $db->prepare($countSql);
                $countStmt->execute($countParams);
                $totalRecords = $countStmt->fetch()['total'];
                $totalPages = ceil($totalRecords / $perPage);
                
                // Get paginated positions
                $limit = (int)$perPage;
                $offsetValue = (int)$offset;
                if ($hasStepColumn) {
                    $sql = "SELECT ps1.* 
                            FROM position_salary ps1
                            INNER JOIN (
                                SELECT position_title, salary_grade, COALESCE(step, 1) as step, MIN(id) as min_id
                                FROM position_salary
                                GROUP BY position_title, salary_grade, COALESCE(step, 1)
                            ) ps2 ON ps1.position_title = ps2.position_title AND ps1.salary_grade = ps2.salary_grade AND COALESCE(ps1.step, 1) = ps2.step AND ps1.id = ps2.min_id
                            WHERE $whereClause 
                            ORDER BY ps1.position_title ASC, ps1.salary_grade ASC, COALESCE(ps1.step, 1) ASC
                            LIMIT $limit OFFSET $offsetValue";
                } else {
                    $sql = "SELECT ps1.* 
                            FROM position_salary ps1
                            INNER JOIN (
                                SELECT position_title, MIN(id) as min_id
                                FROM position_salary
                                GROUP BY position_title
                            ) ps2 ON ps1.position_title = ps2.position_title AND ps1.id = ps2.min_id
                            WHERE $whereClause 
                            ORDER BY ps1.position_title ASC
                            LIMIT $limit OFFSET $offsetValue";
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $positions = $stmt->fetchAll();
                
                // Get unique salary grades for filter
                $gradesStmt = $db->query("SELECT DISTINCT salary_grade FROM position_salary ORDER BY salary_grade ASC");
                $salaryGrades = $gradesStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Get statistics
                $statsStmt = $db->query("
                    SELECT 
                        COUNT(*) as total_positions,
                        AVG(annual_salary) as avg_salary,
                        MAX(annual_salary) as max_salary,
                        MIN(annual_salary) as min_salary
                    FROM position_salary
                ");
                $stats = $statsStmt->fetch();
                
                echo json_encode([
                    'success' => true,
                    'positions' => $positions,
                    'hasStepColumn' => $hasStepColumn,
                    'pagination' => [
                        'page' => $page,
                        'totalPages' => $totalPages,
                        'totalRecords' => $totalRecords,
                        'perPage' => $perPage,
                        'offset' => $offset
                    ],
                    'filters' => [
                        'search' => $search,
                        'salary_grade' => $salaryGradeFilter
                    ],
                    'salaryGrades' => $salaryGrades,
                    'stats' => $stats
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error fetching positions: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'download_template':
            if (ob_get_length()) { ob_clean(); }
            // CSV template for batch import (position_title, salary_grade, step, monthly_salary)
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="positions_import_template_' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['position_title', 'salary_grade', 'step', 'monthly_salary']);
            // Example rows (monthly amounts)
            fputcsv($output, ['Professor', 20, 1, 57347]);
            fputcsv($output, ['Instructor', 12, 1, 29165]);
            fputcsv($output, ['Administrative Assistant', 8, 1, 19744]);
            fclose($output);
            logAction('POSITION_TEMPLATE_DOWNLOAD', "Downloaded positions batch import template");
            exit;

        case 'batch_import':
            if (empty($_FILES['csv_file']['tmp_name'])) {
                throw new Exception('Please upload a CSV file.');
            }
            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload failed. Please try again.');
            }
            $path = $file['tmp_name'];
            if (!is_uploaded_file($path) || !is_readable($path)) {
                throw new Exception('Invalid file.');
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new Exception('Could not read the uploaded file.');
            }
            $header = fgetcsv($handle);
            $header = array_map('trim', $header ?: []);
            // Normalize: accept various header names
            $colMap = [];
            foreach (['position_title', 'position title', 'position', 'title'] as $k) {
                foreach ($header as $i => $h) {
                    if (strtolower($h) === $k || strtolower(str_replace(' ', '_', $h)) === $k) {
                        $colMap['position_title'] = $i;
                        break 2;
                    }
                }
            }
            foreach (['salary_grade', 'salary grade', 'grade', 'sg'] as $k) {
                foreach ($header as $i => $h) {
                    if (strtolower($h) === $k || strtolower(str_replace(' ', '_', $h)) === $k) {
                        $colMap['salary_grade'] = $i;
                        break 2;
                    }
                }
            }
            foreach (['step', 'steps'] as $k) {
                foreach ($header as $i => $h) {
                    if (strtolower($h) === $k || strtolower(str_replace(' ', '_', $h)) === $k) {
                        $colMap['step'] = $i;
                        break 2;
                    }
                }
            }
            foreach (['monthly_salary', 'monthly salary', 'monthly', 'annual_salary', 'annual salary', 'annual', 'salary'] as $k) {
                foreach ($header as $i => $h) {
                    if (strtolower($h) === $k || strtolower(str_replace(' ', '_', $h)) === $k) {
                        $colMap['salary'] = $i;
                        $colMap['salary_is_monthly'] = in_array($k, ['monthly_salary', 'monthly salary', 'monthly']);
                        break 2;
                    }
                }
            }
            if (!isset($colMap['position_title']) || !isset($colMap['salary_grade']) || !isset($colMap['salary'])) {
                fclose($handle);
                throw new Exception('CSV must have columns: position_title, salary_grade, monthly_salary. Download the template and use the same headers.');
            }
            $created = 0;
            $errors = [];
            $rowNum = 1;
            $insertStmtWithStep = $hasStepColumn ? $db->prepare("INSERT INTO position_salary (position_title, salary_grade, step, annual_salary) VALUES (?, ?, ?, ?)") : null;
            $insertStmtNoStep = !$hasStepColumn ? $db->prepare("INSERT INTO position_salary (position_title, salary_grade, annual_salary) VALUES (?, ?, ?)") : null;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (empty(array_filter(array_map('trim', $row)))) {
                    continue;
                }
                $positionTitle = trim($row[$colMap['position_title']] ?? '');
                $salaryGrade = (int)(trim($row[$colMap['salary_grade']] ?? ''));
                $step = isset($colMap['step']) ? (int)(trim($row[$colMap['step']] ?? '1')) : 1;
                $salaryVal = (float)preg_replace('/[^0-9.]/', '', trim($row[$colMap['salary']] ?? ''));
                if (empty($positionTitle)) {
                    $errors[] = "Row $rowNum: Position title is required.";
                    continue;
                }
                if ($salaryGrade < 0 || $salaryGrade > 33) {
                    $errors[] = "Row $rowNum: Salary grade must be between 0 and 33 (got: $salaryGrade).";
                    continue;
                }
                if ($hasStepColumn && ($step < 1 || $step > 8)) {
                    $errors[] = "Row $rowNum: Step must be between 1 and 8 (got: $step).";
                    continue;
                }
                if ($salaryVal <= 0) {
                    $errors[] = "Row $rowNum: Monthly salary must be greater than 0.";
                    continue;
                }
                $monthlySalary = !empty($colMap['salary_is_monthly']) ? $salaryVal : ($salaryVal / 12);
                try {
                    $insertOk = $hasStepColumn 
                        ? $insertStmtWithStep->execute([$positionTitle, $salaryGrade, $step, $monthlySalary])
                        : $insertStmtNoStep->execute([$positionTitle, $salaryGrade, $monthlySalary]);
                    if ($insertOk) {
                        $created++;
                    } else {
                        $errors[] = "Row $rowNum: Failed to insert.";
                    }
                } catch (Exception $e) {
                    $errors[] = "Row $rowNum: " . $e->getMessage();
                }
            }
            fclose($handle);
            logAction('POSITION_BATCH_IMPORT', "Batch import: $created created, " . count($errors) . " errors.");
            echo json_encode([
                'success' => true,
                'message' => $created . ' position(s) created successfully.',
                'created' => $created,
                'errors' => $errors
            ]);
            break;

        case 'export':
            // Export positions as CSV
            $stmt = $db->query("SELECT * FROM position_salary ORDER BY position_title ASC");
            $positions = $stmt->fetchAll();
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="positions_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Add CSV headers
            $headers = ['ID', 'Position Title', 'Salary Grade'];
            if ($hasStepColumn) $headers[] = 'Step';
            $headers = array_merge($headers, ['Annual Salary', 'Monthly Salary']);
            fputcsv($output, $headers);
            
            // Add data rows
            foreach ($positions as $position) {
                $monthlySalary = $position['annual_salary'];
                $annualSalary = $monthlySalary * 12;
                $row = [$position['id'], $position['position_title'], $position['salary_grade']];
                if ($hasStepColumn) $row[] = $position['step'] ?? 1;
                $row = array_merge($row, [number_format($annualSalary, 2), number_format($monthlySalary, 2)]);
                fputcsv($output, $row);
            }
            
            fclose($output);
            logAction('POSITION_EXPORT', "Exported positions to CSV");
            exit;
            
        default:
            throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    // Clear any output
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
